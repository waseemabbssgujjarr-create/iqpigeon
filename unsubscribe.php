<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/notifications.php';

$token = trim($_GET['token'] ?? '');
$message = '';
$success = false;

if ($token !== '') {
    $result = unsubscribe_from_updates($token);
    $success = $result['success'];
    $message = $result['message'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?= page_head('Unsubscribe') ?>
</head>
<body class="bg-background font-body text-on-surface min-h-[100dvh] flex items-center justify-center px-edge-margin">
    <div class="w-full max-w-md text-center">
        <span class="material-symbols-outlined text-5xl text-primary mb-md"><?= $success ? 'check_circle' : 'mail' ?></span>
        <h1 class="font-headline text-headline-mob mb-sm"><?= $success ? 'Unsubscribed' : 'Email preferences' ?></h1>
        <p class="text-body-lg text-on-surface-variant mb-lg"><?= sanitize($message ?: 'Invalid unsubscribe link.') ?></p>
        <a href="/index" class="inline-flex h-12 px-lg rounded-xl bg-primary text-on-primary font-title text-title-md items-center">Back to home</a>
    </div>
</body>
</html>
