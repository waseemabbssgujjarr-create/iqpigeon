<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/facebook-oauth.php';
require_once __DIR__ . '/../../includes/integration-settings.php';
require_once __DIR__ . '/../../includes/platform-schema.php';

ensure_oauth_schema();

$code = trim((string) ($_GET['code'] ?? ''));
$state = trim((string) ($_GET['state'] ?? ''));
$errorParam = trim((string) ($_GET['error'] ?? ''));
$errorReason = trim((string) ($_GET['error_reason'] ?? ''));
$errorDescription = trim((string) ($_GET['error_description'] ?? ''));

facebook_oauth_log('Callback hit', [
    'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
    'query_string'=> $_SERVER['QUERY_STRING'] ?? '',
    'has_code'    => $code !== '',
    'has_state'   => $state !== '',
    'error'       => $errorParam,
    'error_reason'=> $errorReason,
    'status'      => facebook_oauth_debug_status(),
]);

if ($errorParam !== '') {
    facebook_oauth_log('Callback OAuth error from Meta', [
        'error'            => $errorParam,
        'error_reason'     => $errorReason,
        'error_description'=> $errorDescription,
    ]);
    $_SESSION['facebook_auth_error'] = ($errorParam === 'access_denied' || $errorReason === 'user_denied')
        ? 'Facebook sign-in was cancelled.'
        : 'Facebook sign-in failed. Please try again.';
    redirect(!empty($_SESSION['iqp2_return']) ? iqp2_public_path('login.php') : '/login.php');
}

if ($code === '' || $state === '') {
    facebook_oauth_log('Callback incomplete: missing code or state', [
        'missing_code'  => $code === '',
        'missing_state' => $state === '',
        'get_keys'      => array_keys($_GET),
    ]);
    $_SESSION['facebook_auth_error'] = 'Facebook sign-in was incomplete. Please try again.';
    redirect(!empty($_SESSION['iqp2_return']) ? iqp2_public_path('login.php') : '/login.php');
}

try {
    $result = facebook_oauth_handle_callback($code, $state);
} catch (Throwable $e) {
    facebook_oauth_log('Callback exception', ['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
    $_SESSION['facebook_auth_error'] = 'Facebook sign-in failed. Please try again.';
    redirect(!empty($_SESSION['iqp2_return']) ? iqp2_public_path('login.php') : '/login.php');
}

if (!$result['success']) {
    facebook_oauth_log('Callback handle failed', ['message' => $result['message'] ?? 'unknown']);
    $_SESSION['facebook_auth_error'] = $result['message'] ?? 'Facebook sign-in failed. Please try again.';
    redirect(!empty($_SESSION['iqp2_return']) ? iqp2_public_path('login.php') : '/login.php');
}

$user = $result['user'] ?? null;
if (!$user) {
    facebook_oauth_log('Callback success flag set but user missing');
    $_SESSION['facebook_auth_error'] = 'Could not sign you in. Please try again.';
    redirect(!empty($_SESSION['iqp2_return']) ? iqp2_public_path('login.php') : '/login.php');
}

$userId = (int) $user['id'];
$isNewUser = !empty($result['is_new_user']);

if ($isNewUser) {
    ensure_client_starter_bot($userId);
}

if (!empty($_SESSION['iqp2_return'])) {
    unset($_SESSION['iqp2_return']);
    $dest = iqp2_public_path('home.php') . ($isNewUser ? '?facebook=1' : '');
} else {
    $dest = client_post_auth_url($userId) . ($isNewUser ? '?facebook=1' : '');
}
facebook_oauth_log('Callback redirect', ['user_id' => $userId, 'dest' => $dest, 'is_new_user' => $isNewUser]);
redirect($dest);
