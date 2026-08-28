<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/client-shell.php';
require_once __DIR__ . '/../includes/client-header.php';
require_once __DIR__ . '/../includes/abandoned-cart.php';
require_once __DIR__ . '/../includes/platform-settings.php';

$user = require_login();
require_client_feature('cart_recovery');
$userId = (int) $user['id'];
$message = '';
$error = '';

$bots = db_fetch_all('SELECT id, name FROM bots WHERE user_id = ? ORDER BY name ASC', 'i', [$userId]);
$botId = (int) ($_GET['bot_id'] ?? ($bots[0]['id'] ?? 0));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf($_POST['csrf_token'] ?? '')) {
    $botId = (int) ($_POST['bot_id'] ?? 0);
    $owned = db_fetch('SELECT id FROM bots WHERE id = ? AND user_id = ?', 'ii', [$botId, $userId]);
    if (!$owned) {
        $error = 'Invalid bot.';
    } else {
        try {
            abandoned_cart_save_settings($botId, $userId, $_POST);
            $message = 'Abandoned cart settings saved.';
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$settings = $botId ? abandoned_cart_settings($botId, $userId) : null;
$activeTab = 'abandoned';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?= page_head('Abandoned Cart') ?>
</head>
<body class="client-app v2-client bg-background font-body text-on-surface">
<?php client_shell_begin(); ?>
<?php include __DIR__ . '/../includes/client-nav.php'; ?>
<?php client_layout_start(); ?>
    <?php client_page_header('Cart Recovery', ['subtitle' => 'Abandoned cart nudges']); ?>

    <?php if ($message): ?><p class="mb-md text-primary font-medium"><?= sanitize($message) ?></p><?php endif; ?>
    <?php if ($error): ?><p class="mb-md text-error font-medium"><?= sanitize($error) ?></p><?php endif; ?>

    <?php if ($bots === []): ?>
    <div class="bg-surface-container-lowest rounded-2xl p-lg border border-outline-variant text-center">
        <p class="text-body-lg mb-md">Create a bot with a shop catalog first.</p>
        <a href="/client/catalog" class="inline-flex h-12 px-lg rounded-xl bg-primary text-on-primary font-title text-title-md items-center">Set up shop</a>
    </div>
    <?php else: ?>

    <form method="get" class="mb-md">
        <label class="block text-label-sm text-on-surface-variant uppercase mb-xs font-label">Bot</label>
        <select name="bot_id" data-bot-switch onchange="this.form.submit()" class="h-12 px-md rounded-xl bg-surface-container border border-outline-variant text-body-md min-w-[12rem]">
            <?php foreach ($bots as $b): ?>
            <option value="<?= (int) $b['id'] ?>"<?= (int) $b['id'] === $botId ? ' selected' : '' ?>><?= sanitize($b['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </form>

    <section class="bg-surface-container-lowest rounded-2xl p-md border border-outline-variant max-w-2xl">
        <form method="POST" class="space-y-md">
            <input type="hidden" name="csrf_token" value="<?= sanitize(csrf_token()) ?>"/>
            <input type="hidden" name="bot_id" value="<?= $botId ?>"/>
            <label class="flex items-center gap-sm text-body-md">
                <input type="checkbox" name="enabled" value="1"<?= !empty($settings['enabled']) ? ' checked' : '' ?>/>
                Enable abandoned cart recovery
            </label>
            <div>
                <label class="block text-label-sm text-on-surface-variant uppercase mb-xs font-label">Wait time (hours after cart activity)</label>
                <input name="delay_hours" type="number" min="1" max="168" value="<?= (int) ($settings['delay_hours'] ?? 24) ?>" class="w-full h-12 px-md rounded-xl bg-surface-container border border-outline-variant text-body-md"/>
            </div>
            <div>
                <label class="block text-label-sm text-on-surface-variant uppercase mb-xs font-label">WhatsApp message</label>
                <textarea name="message_body" rows="4" class="w-full px-md py-sm rounded-xl bg-surface-container border border-outline-variant text-body-md"><?= sanitize($settings['message_body'] ?? "Hey! You left items in your cart 🛒\n\nReply *cart* to see them or *checkout* to complete your COD order.") ?></textarea>
                <p class="text-label-sm text-outline mt-xs">Cart summary is appended automatically. Runs via cron with booking reminders & drip follow-ups.</p>
            </div>
            <button type="submit" class="h-12 px-xl rounded-xl bg-primary text-on-primary font-title text-title-md">Save settings</button>
        </form>
    </section>
    <?php endif; ?>
<?php client_layout_end(); ?>
<?php client_shell_end(); ?>
<script src="/assets/js/app.js"></script>
</body>
</html>
