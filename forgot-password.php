<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/mailer.php';

if (get_user()) {
    redirect('/client/dashboard');
}

$message = '';
$error = '';
$emailValue = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please try again.';
    } else {
        $emailValue = trim($_POST['email'] ?? '');
        $email = filter_var($emailValue, FILTER_VALIDATE_EMAIL);

        if (!$email) {
            $error = 'Please enter a valid email address.';
        } else {
            $user = db_fetch('SELECT id FROM users WHERE email = ?', 's', [$email]);

            if ($user) {
                $token = generate_token(32);

                db_execute(
                    'UPDATE users SET reset_token = ?, reset_expires_at = DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE id = ?',
                    'si',
                    [$token, (int) $user['id']]
                );

                $resetLink = APP_URL . '/reset-password.php?token=' . urlencode($token);
                if (email_password_reset($email, $resetLink)) {
                    $message = 'We\'ve sent a password reset link to ' . $email . '. Check your inbox (and spam folder).';
                } else {
                    $detail = function_exists('mail_last_error') ? mail_last_error() : '';
                    $error = $detail !== ''
                        ? 'Could not send reset email: ' . $detail
                        : 'Could not send reset email. Please contact support.';
                }
            } else {
                $error = 'No account found with that email address. Please check the spelling or sign up for a free trial.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?= page_head('Forgot Password') ?>
<?= auth_assets() ?>
</head>
<body class="auth-page auth-page--v2">
    <div class="auth-page__layers" aria-hidden="true">
        <div class="auth-page__gradient"></div>
        <div class="auth-page__mesh"></div>
    </div>

    <header class="auth-page__header auth-page__header--back">
        <a href="/login" class="auth-page__back">
            <span class="material-symbols-outlined">arrow_back</span>
            <span>Back to login</span>
        </a>
    </header>

    <main class="auth-page__main">
        <div class="auth-card v2-glass auth-card--v2">
            <div class="auth-hero">
                <div class="auth-hero__icon">
                    <span class="material-symbols-outlined">lock_reset</span>
                </div>
                <h1 class="auth-hero__title">Reset password</h1>
                <?php if (!$message): ?>
                <p class="auth-hero__subtitle">Enter your email and we&rsquo;ll send a reset link.</p>
                <?php endif; ?>
            </div>

            <?php if ($message): ?>
                <div class="auth-alert auth-alert--success"><?= sanitize($message) ?></div>
                <a href="/login" class="auth-submit v2-btn-pill v2-btn-pill--primary">
                    Back to sign in
                </a>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="auth-alert"><?= sanitize($error) ?></div>
            <?php endif; ?>

            <?php if (!$message): ?>
            <form method="POST" class="auth-form auth-form--stacked">
                <input type="hidden" name="csrf_token" value="<?= sanitize(csrf_token()) ?>"/>

                <div class="auth-field">
                    <label for="email" class="auth-label">Email</label>
                    <input
                        type="email"
                        id="email"
                        name="email"
                        value="<?= sanitize($emailValue) ?>"
                        required
                        autocomplete="email"
                        class="auth-input--standalone"
                        placeholder="you@company.com"
                    />
                </div>

                <button type="submit" class="auth-submit v2-btn-pill v2-btn-pill--primary">
                    Send reset link
                </button>
            </form>
            <?php endif; ?>
        </div>
    </main>
    <script src="/assets/js/app.js"></script>
</body>
</html>
