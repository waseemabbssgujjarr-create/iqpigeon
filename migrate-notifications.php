<?php
/**
 * One-time migration — /migrate-notifications.php then DELETE.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/migrations/notifications_tables.php';

security_require_privileged_key();

$messages = [];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = run_notifications_migration();
    $messages = $result['messages'];
    $errors = $result['errors'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?= page_head('Notifications Migration') ?>
</head>
<body class="bg-background font-body text-on-surface min-h-[100dvh] flex items-center justify-center p-edge-margin">
    <div class="w-full max-w-md bg-surface-container-lowest rounded-2xl p-lg border border-outline-variant">
        <h1 class="font-headline text-headline-mob mb-md">Notifications Migration</h1>
        <p class="text-body-md text-on-surface-variant mb-md">Creates notifications, system_updates, and update_subscribers tables.</p>

        <?php foreach ($errors as $err): ?>
            <div class="bg-error-container text-on-error-container rounded-xl p-md mb-md text-body-md"><?= sanitize($err) ?></div>
        <?php endforeach; ?>
        <?php foreach ($messages as $msg): ?>
            <div class="bg-primary-container/20 border border-primary text-on-primary-container rounded-xl p-md mb-md text-body-md"><?= sanitize($msg) ?></div>
        <?php endforeach; ?>

        <?php if (empty($messages)): ?>
        <form method="POST">
            <button type="submit" class="w-full h-14 rounded-xl bg-primary text-on-primary font-title text-title-md active:scale-95">Run Migration</button>
        </form>
        <?php else: ?>
        <p class="text-error text-body-md font-medium">Delete migrate-notifications.php from your server.</p>
        <?php endif; ?>
    </div>
</body>
</html>
