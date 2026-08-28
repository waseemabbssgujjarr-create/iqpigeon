<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/google-oauth.php';
require_once __DIR__ . '/../../includes/integration-settings.php';
require_once __DIR__ . '/../../includes/platform-schema.php';

ensure_oauth_schema();

$code = trim((string) ($_GET['code'] ?? ''));
$state = trim((string) ($_GET['state'] ?? ''));
$errorParam = trim((string) ($_GET['error'] ?? ''));

if ($errorParam !== '') {
    $_SESSION['google_auth_error'] = $errorParam === 'access_denied'
        ? 'Google sign-in was cancelled.'
        : 'Google sign-in failed (' . $errorParam . ').';
    redirect(!empty($_SESSION['iqp2_return']) ? iqp2_public_path('login.php') : '/login.php');
}

if ($code === '' || $state === '') {
    $_SESSION['google_auth_error'] = 'Google sign-in was incomplete. Please try again.';
    redirect(!empty($_SESSION['iqp2_return']) ? iqp2_public_path('login.php') : '/login.php');
}

$result = google_oauth_handle_callback($code, $state);

if (!$result['success']) {
    $_SESSION['google_auth_error'] = $result['message'];
    redirect(!empty($_SESSION['iqp2_return']) ? iqp2_public_path('login.php') : '/login.php');
}

$user = $result['user'] ?? null;
if (!$user) {
    $_SESSION['google_auth_error'] = 'Could not sign you in. Please try again.';
    redirect(!empty($_SESSION['iqp2_return']) ? iqp2_public_path('login.php') : '/login.php');
}

$userId = (int) $user['id'];
$isNewUser = !empty($result['is_new_user']);

if ($isNewUser) {
    auth_issue_remember_token($userId);
    ensure_client_starter_bot($userId);
}

if (!empty($_SESSION['iqp2_return'])) {
    unset($_SESSION['iqp2_return']);
    redirect(iqp2_public_path('home.php') . ($isNewUser ? '?google=1' : ''));
}
redirect(client_post_auth_url($userId) . ($isNewUser ? '?google=1' : ''));
