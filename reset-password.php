<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/auth.php';

if (get_user()) {
    redirect('/client/dashboard');
}

$token = trim(urldecode($_GET['token'] ?? $_POST['token'] ?? ''));
$error = '';
$success = false;
$validToken = false;

if ($token !== '') {
    $user = db_fetch(
        'SELECT id FROM users WHERE reset_token = ? AND reset_expires_at > NOW()',
        's',
        [$token]
    );
    $validToken = $user !== null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please try again.';
    } elseif (!$validToken) {
        $error = 'This reset link is invalid or has expired.';
    } else {
        $password = $_POST['password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if (strlen($password) < 8) {
            $error = 'Password must be at least 8 characters.';
        } elseif ($password !== $confirm) {
            $error = 'Passwords do not match.';
        } else {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            db_execute(
                'UPDATE users SET password = ?, reset_token = NULL, reset_expires_at = NULL, login_attempts = 0, locked_until = NULL WHERE id = ?',
                'si',
                [$hash, (int) $user['id']]
            );
            $success = true;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?= page_head('Reset Password') ?>
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
                    <span class="material-symbols-outlined">vpn_key</span>
                </div>
                <h1 class="auth-hero__title">New password</h1>
                <p class="auth-hero__subtitle">Choose a strong password for your account.</p>
            </div>

            <?php if ($success): ?>
                <div class="auth-alert auth-alert--success">Password updated successfully!</div>
                <a href="/login" class="auth-submit v2-btn-pill v2-btn-pill--primary">
                    Sign in
                </a>
            <?php elseif (!$validToken && $_SERVER['REQUEST_METHOD'] !== 'POST'): ?>
                <div class="auth-alert">This reset link is invalid or has expired.</div>
                <a href="/forgot-password" class="auth-submit v2-btn-pill v2-btn-pill--ghost">
                    Request new link
                </a>
            <?php else: ?>
                <?php if ($error): ?>
                    <div class="auth-alert"><?= sanitize($error) ?></div>
                <?php endif; ?>

                <form method="POST" class="auth-form auth-form--stacked">
                    <input type="hidden" name="csrf_token" value="<?= sanitize(csrf_token()) ?>"/>
                    <input type="hidden" name="token" value="<?= sanitize($token) ?>"/>

                    <div class="auth-field">
                        <label for="password" class="auth-label">New password</label>
                        <input
                            type="password"
                            id="password"
                            name="password"
                            required
                            minlength="8"
                            autocomplete="new-password"
                            class="auth-input--standalone"
                            placeholder="Min. 8 characters"
                        />
                    </div>

                    <div class="auth-field">
                        <label for="confirm_password" class="auth-label">Confirm password</label>
                        <input
                            type="password"
                            id="confirm_password"
                            name="confirm_password"
                            required
                            minlength="8"
                            autocomplete="new-password"
                            class="auth-input--standalone"
                            placeholder="Repeat password"
                        />
                    </div>

                    <button type="submit" class="auth-submit v2-btn-pill v2-btn-pill--primary">
                        Update password
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </main>
    <script src="/assets/js/app.js"></script>
</body>
</html>
