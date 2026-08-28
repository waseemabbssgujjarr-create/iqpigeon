<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';

if (!admin_access_granted()) {
    http_response_code(404);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head><?= page_head('Not Found') ?></head>
    <body class="bg-background font-body text-on-surface min-h-[100dvh] flex items-center justify-center px-edge-margin">
        <div class="text-center">
            <h1 class="font-headline text-headline-mob mb-sm">404</h1>
            <p class="text-body-md text-on-surface-variant">Page not found.</p>
        </div>
    </body>
    </html>
    <?php
    exit;
}

$user = get_user();
if ($user) {
    redirect($user['role'] === 'admin' ? '/admin/dashboard' : '/client/dashboard');
}

$error = '';
$locked = false;
$emailValue = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please try again.';
    } else {
        $emailValue = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $result = login($emailValue, $password, ['admin_only' => true]);

        if ($result['success']) {
            redirect('/admin/dashboard');
        }

        $error = $result['message'];
        $locked = !empty($result['locked']);
    }
}

$accessKey = defined('ADMIN_ACCESS_KEY') && ADMIN_ACCESS_KEY !== '' ? ADMIN_ACCESS_KEY : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?= page_head('Admin Sign In') ?>
<?= auth_assets() ?>
</head>
<body class="auth-page auth-page--v2 auth-page--admin">
    <div class="auth-page__layers" aria-hidden="true">
        <div class="auth-page__gradient"></div>
        <div class="auth-page__mesh"></div>
    </div>

    <main class="auth-page__main">
        <div class="auth-card v2-glass auth-card--v2">
            <div class="auth-card__logo">
                <?= brand_logo_markup('brand-logo-img', 'light') ?>
            </div>

            <div class="auth-hero">
                <h1 class="auth-hero__title">Admin sign in</h1>
                <p class="auth-hero__subtitle">Platform management access only.</p>
            </div>

            <?php if ($locked): ?>
                <div class="auth-alert auth-alert--lock">
                    <span class="material-symbols-outlined">lock</span>
                    Too many attempts. Try again in 15 minutes.
                </div>
            <?php elseif ($error): ?>
                <div class="auth-alert"><?= sanitize($error) ?></div>
            <?php endif; ?>

            <form method="POST" class="auth-form">
                <?php if ($accessKey !== ''): ?>
                    <input type="hidden" name="access_key" value="<?= sanitize($accessKey) ?>"/>
                <?php endif; ?>
                <input type="hidden" name="csrf_token" value="<?= sanitize(csrf_token()) ?>"/>

                <div class="auth-field">
                    <label for="email" class="auth-label">Admin email</label>
                    <div class="auth-input-group">
                        <span class="auth-input-group__icon material-symbols-outlined" aria-hidden="true">mail</span>
                        <input
                            type="email"
                            id="email"
                            name="email"
                            value="<?= sanitize($emailValue) ?>"
                            required
                            autocomplete="email"
                            class="auth-input auth-input--grouped"
                            placeholder="admin@yourdomain.com"
                        />
                    </div>
                </div>

                <div class="auth-field">
                    <div class="auth-field__row">
                        <label for="password" class="auth-label">Password</label>
                        <a href="/forgot-password" class="auth-label-link">Forgot password?</a>
                    </div>
                    <div class="auth-input-group">
                        <span class="auth-input-group__icon material-symbols-outlined" aria-hidden="true">lock</span>
                        <input
                            type="password"
                            id="password"
                            name="password"
                            required
                            autocomplete="current-password"
                            class="auth-input auth-input--grouped"
                            placeholder="Enter your password"
                        />
                    </div>
                </div>

                <button
                    type="submit"
                    <?= $locked ? 'disabled' : '' ?>
                    class="auth-submit v2-btn-pill v2-btn-pill--primary"
                >
                    Sign in
                </button>
            </form>

            <p class="auth-card__footer">
                <a href="/login">← Client sign in</a>
            </p>
        </div>
    </main>
    <script src="/assets/js/app.js"></script>
</body>
</html>
