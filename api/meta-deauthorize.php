<?php
/**
 * Meta Deauthorize callback — called when a user removes the app from Facebook settings.
 * Configure in Meta → Facebook Login for Business → Settings → Deauthorize callback URL.
 */
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/integration-settings.php';
require_once __DIR__ . '/../includes/meta-signed-request.php';
require_once __DIR__ . '/../includes/platform-schema.php';

header('Content-Type: text/plain; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Method not allowed';
    exit;
}

$signedRequest = trim((string) ($_POST['signed_request'] ?? ''));
if ($signedRequest === '') {
    http_response_code(400);
    echo 'Missing signed_request';
    exit;
}

$creds = integration_meta_credentials();
$appSecret = trim($creds['app_secret'] ?? '');
if ($appSecret === '') {
    error_log('[meta-deauthorize] App secret not configured');
    http_response_code(500);
    echo 'Not configured';
    exit;
}

$data = meta_parse_signed_request($signedRequest, $appSecret);
if ($data === null) {
    error_log('[meta-deauthorize] Invalid signed_request');
    http_response_code(400);
    echo 'Invalid signed_request';
    exit;
}

$facebookUserId = trim((string) ($data['user_id'] ?? ''));
if ($facebookUserId === '') {
    http_response_code(200);
    echo 'OK';
    exit;
}

ensure_oauth_schema();

if (db_column_exists('users', 'facebook_id')) {
    db_execute(
        'UPDATE users SET facebook_id = NULL WHERE facebook_id = ?',
        's',
        [$facebookUserId]
    );
}

error_log('[meta-deauthorize] Cleared facebook_id for Meta user ' . $facebookUserId);
http_response_code(200);
echo 'OK';
