<?php
/**
 * Facebook sign-in button partial.
 * @var string $facebookMode login|register
 */
require_once __DIR__ . '/facebook-oauth.php';
require_once __DIR__ . '/integration-settings.php';

$facebookMode = $facebookMode ?? 'login';

if (!integration_facebook_signin_enabled()) {
    return;
}

$facebookReady = facebook_signin_available();
$href = $facebookReady
    ? '/api/auth/facebook-start.php?mode=' . rawurlencode($facebookMode)
    : '/login.php?facebook_error=not_configured';
if (!$facebookReady && $facebookMode === 'register') {
    $href='/register?facebook_error=not_configured';
}
?>
<a href="<?= sanitize($href) ?>"
   class="auth-oauth-btn auth-oauth-btn--facebook<?= $facebookReady ? '' : ' opacity-80' ?>"
   <?= $facebookReady ? '' : ' title="Facebook sign-in will be available soon"' ?>>
    <svg width="20" height="20" viewBox="0 0 24 24" aria-hidden="true">
        <path fill="currentColor" d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/>
    </svg>
    Continue with Facebook
</a>
