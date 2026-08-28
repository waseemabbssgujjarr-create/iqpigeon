<?php
/**
 * One-time WhatsApp migration — accessible at /migrate-whatsapp.php
 * DELETE THIS FILE immediately after running successfully.
 * (/install/ is blocked by .htaccess for security)
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/migrations/whatsapp_tables.php';

security_require_privileged_key();

$messages = [];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = run_whatsapp_embedded_signup_migration();
    $messages = $result['messages'];
    $errors = $result['errors'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?= page_head('WhatsApp Migration') ?>
</head>
<body class="bg-background font-body text-on-surface min-h-[100dvh] flex items-center justify-center p-edge-margin">
    <div class="w-full max-w-md bg-surface-container-lowest rounded-2xl p-lg border border-outline-variant shadow-lg">
        <div class="flex items-center gap-sm mb-md">
            <span class="material-symbols-outlined text-primary text-3xl">database</span>
            <h1 class="font-headline text-headline-mob">WhatsApp DB Migration</h1>
        </div>

        <p class="text-body-md text-on-surface-variant mb-md">
            Creates <code>client_whatsapp_accounts</code> and <code>whatsapp_messages_log</code> tables.
        </p>

        <?php foreach ($errors as $err): ?>
            <div class="bg-error-container text-on-error-container rounded-xl p-md mb-md text-body-md"><?= sanitize($err) ?></div>
        <?php endforeach; ?>

        <?php foreach ($messages as $msg): ?>
            <div class="bg-primary-container/20 border border-primary text-on-primary-container rounded-xl p-md mb-md text-body-md"><?= sanitize($msg) ?></div>
        <?php endforeach; ?>

        <?php if (empty($messages)): ?>
            <form method="POST">
                <button type="submit" class="w-full h-14 rounded-xl bg-primary text-on-primary font-title text-title-md active:scale-95 transition-transform">
                    Run Migration
                </button>
            </form>
        <?php else: ?>
            <p class="text-label-sm font-label text-error mt-md">⚠ Delete <strong>migrate-whatsapp.php</strong> from public_html now.</p>
        <?php endif; ?>
    </div>
</body>
</html>
