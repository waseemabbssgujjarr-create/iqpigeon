<?php
/**
 * Register FCM device token from Android/iOS app.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/push-notifications.php';

header('Content-Type: application/json');

$user = get_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not signed in']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'POST required']);
    exit;
}

security_require_api_csrf();

$input = security_cached_json_body();
if ($input === []) {
    $input = $_POST;
}
$token = trim((string) ($input['token'] ?? ''));
$platform = trim((string) ($input['platform'] ?? 'android'));
$appVersion = trim((string) ($input['app_version'] ?? ''));

if ($token === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Token required']);
    exit;
}

$ok = push_register_device_token((int) $user['id'], $token, $platform, $appVersion);

echo json_encode([
    'success' => $ok,
    'push_enabled' => push_notifications_enabled(),
]);
