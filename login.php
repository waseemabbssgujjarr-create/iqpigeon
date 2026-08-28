<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/iqp-ui.php';
require_once __DIR__ . '/includes/integration-settings.php';
require_once __DIR__ . '/includes/google-oauth.php';
require_once __DIR__ . '/includes/facebook-oauth.php';

if (get_user()) {
    $user = get_user();
    redirect($user['role'] === 'admin' ? '/admin/dashboard' : '/client/dashboard');
}

$error = '';
$locked = false;
$emailValue = '';

if (!empty($_SESSION['google_auth_error'])) {
    $error = (string) $_SESSION['google_auth_error'];
    unset($_SESSION['google_auth_error']);
} elseif (!empty($_SESSION['facebook_auth_error'])) {
    $error = (string) $_SESSION['facebook_auth_error'];
    unset($_SESSION['facebook_auth_error']);
} elseif (trim($_GET['google_error'] ?? '') === 'not_configured') {
    $error = 'Google sign-in is not set up on this site yet.';
} elseif (trim($_GET['facebook_error'] ?? '') === 'not_configured') {
    $error = 'Facebook sign-in is not set up on this site yet.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please try again.';
    } else {
        $emailValue = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $remember = !empty($_POST['remember_me']);
        $result = login($emailValue, $password, ['client_only' => true, 'remember' => $remember]);
        if ($result['success']) {
            if (email_verification_enabled() && !is_user_email_verified($result['user'])) {
                redirect('/verify-email.php');
            }
            redirect(client_post_auth_url((int) $result['user']['id']));
        }
        $error = $result['message'];
        if (!empty($result['admin_portal'])) {
            $error .= ' <a href="' . sanitize(admin_login_url()) . '" class="underline font-medium">Go to admin sign in →</a>';
        }
        $locked = !empty($result['locked']);
    }
}

$showGoogle = google_oauth_configured();
$showFacebook = facebook_oauth_configured();
iqp_auth_begin('Sign In');
?>
<div class="iqp-auth-page min-h-screen flex items-center justify-center p-4 sm:p-8">
  <div class="iqp-auth-layout w-full max-w-5xl grid grid-cols-1 lg:grid-cols-2 gap-8 items-center">
    <?php $authMode = 'login'; include __DIR__ . '/includes/views/auth-hero.php'; ?>
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 sm:p-8 iqp-auth-form-card">
      <div class="iqp-auth-form-logo">
        <?= brand_logo_markup('brand-logo-img iqp-auth-brand-logo', 'light') ?>
      </div>
      <h2 class="text-[19px] font-bold text-slate-800">Welcome Back!</h2>
      <p class="text-[12.5px] text-slate-400 mb-5">Sign in to your IQPigeon account</p>
      <?php if ($locked): ?>
        <div class="mb-4 text-[12.5px] text-red-600 bg-red-50 border border-red-100 rounded-lg p-3">Too many attempts. Try again in 15 minutes.</div>
      <?php elseif ($error): ?>
        <div class="mb-4 text-[12.5px] text-red-600 bg-red-50 border border-red-100 rounded-lg p-3"><?= $error ?></div>
      <?php endif; ?>
      <?php if ($showGoogle): ?>
      <a href="/api/auth/google-start.php?mode=login" class="w-full border border-slate-200 rounded-lg py-2.5 text-[13px] font-medium text-slate-600 flex items-center justify-center gap-2 mb-2.5">
        <svg width="16" height="16" viewBox="0 0 24 24"><path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 0 1-2.2 3.32v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.1z"/><path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.99.66-2.25 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.85A11 11 0 0 0 12 23z"/><path fill="#FBBC05" d="M5.84 14.09A6.6 6.6 0 0 1 5.5 12c0-.73.13-1.43.34-2.09V7.06H2.18A11 11 0 0 0 1 12c0 1.77.42 3.45 1.18 4.94l3.66-2.85z"/><path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1a11 11 0 0 0-9.82 6.06l3.66 2.85C6.71 7.31 9.14 5.38 12 5.38z"/></svg>
        Continue with Google
      </a>
      <?php endif; ?>
      <?php if ($showFacebook): ?>
      <a href="/api/auth/facebook-start.php?mode=login" class="w-full border border-slate-200 rounded-lg py-2.5 text-[13px] font-medium text-slate-600 flex items-center justify-center gap-2 mb-4">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="#1877F2"><path d="M22 12a10 10 0 1 0-11.56 9.88v-6.99H7.9V12h2.54V9.8c0-2.5 1.49-3.89 3.77-3.89 1.09 0 2.24.2 2.24.2v2.46h-1.26c-1.24 0-1.63.77-1.63 1.56V12h2.78l-.44 2.89h-2.34v6.99A10 10 0 0 0 22 12z"/></svg>
        Continue with Facebook
      </a>
      <?php endif; ?>
      <?php if ($showGoogle || $showFacebook): ?>
      <div class="flex items-center gap-3 text-[11px] text-slate-400 my-3"><div class="flex-1 h-px bg-slate-100"></div>or<div class="flex-1 h-px bg-slate-100"></div></div>
      <?php endif; ?>
      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= sanitize(csrf_token()) ?>"/>
        <div class="text-[11.5px] text-slate-500 mb-1">Email Address</div>
        <input type="email" name="email" required autocomplete="email" value="<?= sanitize($emailValue) ?>" class="w-full border border-slate-200 rounded-lg px-3 py-2.5 text-[13px] mb-3" placeholder="Enter your email"/>
        <div class="text-[11.5px] text-slate-500 mb-1">Password</div>
        <input type="password" name="password" required autocomplete="current-password" class="w-full border border-slate-200 rounded-lg px-3 py-2.5 text-[13px] mb-2" placeholder="Enter your password"/>
        <div class="flex items-center justify-between text-[12px] mb-4">
          <label class="flex items-center gap-1.5 text-slate-500"><input type="checkbox" name="remember_me" value="1" checked class="accent-emerald-500"/>Remember me</label>
          <a href="/forgot-password" class="text-[#1FA855] font-medium">Forgot Password?</a>
        </div>
        <button type="submit" class="w-full bg-[#1FA855] text-white rounded-lg py-2.5 text-[13.5px] font-semibold" <?= $locked ? 'disabled' : '' ?>>Sign In</button>
      </form>
      <div class="text-center text-[12.5px] text-slate-500 mt-4">Don't have an account? <a href="/register" class="text-[#1FA855] font-semibold">Sign Up</a></div>
      <div class="text-center text-[11px] text-slate-400 mt-6">Your data is safe and secure with us.</div>
    </div>
  </div>
</div>
<?php iqp_auth_end();
