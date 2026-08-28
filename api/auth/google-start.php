<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/google-oauth.php';
require_once __DIR__ . '/../../includes/integration-settings.php';

if (!google_signin_available()) {
    $_SESSION['google_auth_error'] = 'Google sign-in is not available yet. Please use email and password for now.';
    $mode = ($_GET['mode'] ?? '') === 'register' ? '/register.php' : '/login.php';
    redirect($mode);
}

redirect(google_oauth_start_url($_GET['mode'] ?? 'login'));
