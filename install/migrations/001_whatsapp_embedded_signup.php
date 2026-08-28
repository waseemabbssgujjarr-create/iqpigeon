<?php
/**
 * One-time migration: WhatsApp Embedded Signup tables.
 * NOTE: /install/ is blocked by .htaccess — use /migrate-whatsapp.php instead.
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/migrations/whatsapp_tables.php';

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
    <div class="w-full max-w-md bg-surface-container-lowest rounded-2xl p-lg border border-outline-variant">
        <h1 class="font-headline text-headline-mob mb-md">WhatsApp Migration</h1>
        <div class="bg-error-container text-on-error-container rounded-xl p-md mb-md text-body-md">
            This URL is blocked by .htaccess. Use:
            <a href="/migrate-whatsapp" class="underline font-bold">/migrate-whatsapp.php</a>
        </div>
        <?php foreach ($errors as $err): ?>
            <div class="bg-error-container text-on-error-container rounded-xl p-md mb-md"><?= sanitize($err) ?></div>
        <?php endforeach; ?>
        <?php foreach ($messages as $msg): ?>
            <div class="bg-primary-container/20 border border-primary text-on-primary-container rounded-xl p-md mb-md"><?= sanitize($msg) ?></div>
        <?php endforeach; ?>
        <?php if (empty($messages)): ?>
            <a href="/migrate-whatsapp" class="block w-full h-14 rounded-xl bg-primary text-on-primary font-title text-title-md text-center leading-[3.5rem]">Go to migrate-whatsapp.php</a>
        <?php endif; ?>
    </div>
</body>
</html>
