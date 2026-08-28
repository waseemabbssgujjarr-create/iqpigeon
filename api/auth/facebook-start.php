<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/facebook-oauth.php';
require_once __DIR__ . '/../../includes/integration-settings.php';

if (!facebook_signin_available()) {
    facebook_oauth_log('Start rejected: sign-in not available');
    $_SESSION['facebook_auth_error'] = 'Facebook sign-in is not available yet. Please use email and password for now.';
    $mode = ($_GET['mode'] ?? '') === 'register' ? '/register.php' : '/login.php';
    redirect($mode);
}

$url = facebook_oauth_start_url($_GET['mode'] ?? 'login');
facebook_oauth_log('Start redirect', ['url' => strtok($url, '?'), 'mode' => $_GET['mode'] ?? 'login']);
redirect($url);
