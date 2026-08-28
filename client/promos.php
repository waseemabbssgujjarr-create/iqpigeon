<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/client-shell.php';
require_once __DIR__ . '/../includes/client-header.php';
require_once __DIR__ . '/../includes/promo-codes.php';

$user = require_login();
$userId = (int) $user['id'];
$message = '';
$error = '';

$bots = db_fetch_all('SELECT id, name FROM bots WHERE user_id = ? ORDER BY name ASC', 'i', [$userId]);
$botId = (int) ($_GET['bot_id'] ?? ($bots[0]['id'] ?? 0));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf($_POST['csrf_token'] ?? '')) {
    $botId = (int) ($_POST['bot_id'] ?? 0);
    $action = $_POST['action'] ?? '';

    $owned = db_fetch('SELECT id FROM bots WHERE id = ? AND user_id = ?', 'ii', [$botId, $userId]);
    if (!$owned) {
        $error = 'Invalid bot.';
    } elseif ($action === 'add') {
        try {
            promo_code_save($botId, $userId, $_POST);
            $message = 'Promo code created.';
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    } elseif ($action === 'update') {
        try {
            promo_code_save($botId, $userId, $_POST, (int) ($_POST['promo_id'] ?? 0));
            $message = 'Promo code updated.';
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    } elseif ($action === 'delete') {
        promo_code_delete((int) ($_POST['promo_id'] ?? 0), $botId, $userId);
        $message = 'Promo code removed.';
    }
}

$promos = $botId ? promo_codes_for_bot($botId, $userId) : [];
$activeTab = 'promos';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?= page_head('Promo Codes') ?>
</head>
<body class="client-app v2-client bg-background font-body text-on-surface">
<?php client_shell_begin(); ?>
<?php include __DIR__ . '/../includes/client-nav.php'; ?>
<?php client_layout_start(['width' => 'wide']); ?>
    <?php client_page_header('Promos', ['subtitle' => 'WhatsApp discount codes']); ?>

    <?php if ($message): ?><p class="mb-md text-primary font-medium"><?= sanitize($message) ?></p><?php endif; ?>
    <?php if ($error): ?><p class="mb-md text-error font-medium"><?= sanitize($error) ?></p><?php endif; ?>

    <?php if ($bots === []): ?>
    <div class="bg-surface-container-lowest rounded-2xl p-lg border border-outline-variant text-center">
        <p class="text-body-lg mb-md">Create a bot first.</p>
        <a href="/client/onboarding" class="inline-flex h-12 px-lg rounded-xl bg-primary text-on-primary font-title text-title-md items-center">Set up bot</a>
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

    <div class="grid gap-lg lg:grid-cols-2">
        <section class="bg-surface-container-lowest rounded-2xl p-md border border-outline-variant">
            <h2 class="font-title text-title-md mb-md">New promo code</h2>
            <form method="POST" class="space-y-md">
                <input type="hidden" name="csrf_token" value="<?= sanitize(csrf_token()) ?>"/>
                <input type="hidden" name="action" value="add"/>
                <input type="hidden" name="bot_id" value="<?= $botId ?>"/>
                <div>
                    <label class="block text-label-sm text-on-surface-variant uppercase mb-xs font-label">Code</label>
                    <input name="code" required placeholder="SAVE10" class="w-full h-12 px-md rounded-xl bg-surface-container border border-outline-variant text-body-md uppercase"/>
                </div>
                <div class="grid grid-cols-2 gap-md">
                    <div>
                        <label class="block text-label-sm text-on-surface-variant uppercase mb-xs font-label">Type</label>
                        <select name="discount_type" class="w-full h-12 px-md rounded-xl bg-surface-container border border-outline-variant text-body-md">
                            <option value="percent">Percent off</option>
                            <option value="fixed">Fixed amount off</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-label-sm text-on-surface-variant uppercase mb-xs font-label">Value</label>
                        <input name="discount_value" type="number" step="0.01" min="0" value="10" class="w-full h-12 px-md rounded-xl bg-surface-container border border-outline-variant text-body-md"/>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-md">
                    <div>
                        <label class="block text-label-sm text-on-surface-variant uppercase mb-xs font-label">Min order (optional)</label>
                        <input name="min_order" type="number" step="0.01" min="0" class="w-full h-12 px-md rounded-xl bg-surface-container border border-outline-variant text-body-md"/>
                    </div>
                    <div>
                        <label class="block text-label-sm text-on-surface-variant uppercase mb-xs font-label">Max uses (optional)</label>
                        <input name="max_uses" type="number" min="1" class="w-full h-12 px-md rounded-xl bg-surface-container border border-outline-variant text-body-md"/>
                    </div>
                </div>
                <div>
                    <label class="block text-label-sm text-on-surface-variant uppercase mb-xs font-label">Expires (optional)</label>
                    <input name="expires_at" type="datetime-local" class="w-full h-12 px-md rounded-xl bg-surface-container border border-outline-variant text-body-md"/>
                </div>
                <label class="flex items-center gap-xs text-body-md">
                    <input type="checkbox" name="is_active" value="1" checked/>
                    Active
                </label>
                <button type="submit" class="h-12 px-xl rounded-xl bg-primary text-on-primary font-title text-title-md">Create code</button>
            </form>
        </section>

        <section class="bg-surface-container-lowest rounded-2xl p-md border border-outline-variant">
            <h2 class="font-title text-title-md mb-md">Active codes (<?= count($promos) ?>)</h2>
            <?php if ($promos === []): ?>
            <p class="text-body-md text-on-surface-variant">No promo codes yet. Create one for launch campaigns or cart recovery.</p>
            <?php else: ?>
            <div class="space-y-md">
                <?php foreach ($promos as $p): ?>
                <form method="POST" class="p-md rounded-xl bg-surface-container-low border border-outline-variant space-y-sm">
                    <input type="hidden" name="csrf_token" value="<?= sanitize(csrf_token()) ?>"/>
                    <input type="hidden" name="action" value="update"/>
                    <input type="hidden" name="bot_id" value="<?= $botId ?>"/>
                    <input type="hidden" name="promo_id" value="<?= (int) $p['id'] ?>"/>
                    <input name="code" value="<?= sanitize($p['code']) ?>" class="w-full h-10 px-sm rounded-lg bg-surface-container-lowest border border-outline-variant text-body-md font-title uppercase"/>
                    <div class="grid grid-cols-2 gap-sm">
                        <select name="discount_type" class="h-10 px-sm rounded-lg bg-surface-container-lowest border border-outline-variant text-body-md">
                            <option value="percent"<?= ($p['discount_type'] ?? '') === 'percent' ? ' selected' : '' ?>>Percent</option>
                            <option value="fixed"<?= ($p['discount_type'] ?? '') === 'fixed' ? ' selected' : '' ?>>Fixed</option>
                        </select>
                        <input name="discount_value" type="number" step="0.01" value="<?= sanitize((string) $p['discount_value']) ?>" class="h-10 px-sm rounded-lg bg-surface-container-lowest border border-outline-variant text-body-md"/>
                    </div>
                    <p class="text-label-sm text-outline">Used: <?= (int) $p['used_count'] ?><?= $p['max_uses'] ? ' / ' . (int) $p['max_uses'] : '' ?></p>
                    <label class="flex items-center gap-xs text-body-md">
                        <input type="checkbox" name="is_active" value="1"<?= !empty($p['is_active']) ? ' checked' : '' ?>/>
                        Active
                    </label>
                    <div class="flex gap-xs">
                        <button type="submit" class="h-10 px-md rounded-lg bg-secondary text-on-secondary text-label-sm font-label">Save</button>
                        <button type="submit" name="action" value="delete" class="h-10 px-md rounded-lg bg-error-container text-on-error-container text-label-sm font-label" onclick="return confirm('Delete this code?')">Delete</button>
                    </div>
                </form>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </section>
    </div>
    <?php endif; ?>
<?php client_layout_end(); ?>
<?php client_shell_end(); ?>
<script src="/assets/js/app.js"></script>
</body>
</html>
