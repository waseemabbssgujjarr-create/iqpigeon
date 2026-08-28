<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/client-shell.php';
require_once __DIR__ . '/../includes/client-header.php';
require_once __DIR__ . '/../includes/drip.php';

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
            drip_sequence_save($botId, $userId, $_POST);
            $message = 'Follow-up sequence added.';
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    } elseif ($action === 'update') {
        try {
            drip_sequence_save($botId, $userId, $_POST, (int) ($_POST['sequence_id'] ?? 0));
            $message = 'Sequence updated.';
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    } elseif ($action === 'delete') {
        drip_sequence_delete((int) ($_POST['sequence_id'] ?? 0), $botId, $userId);
        $message = 'Sequence removed.';
    }
}

$sequences = $botId ? drip_sequences_for_bot($botId, $userId) : [];
$activeTab = 'drip';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?= page_head('Follow-ups') ?>
</head>
<body class="client-app v2-client bg-background font-body text-on-surface">
<?php client_shell_begin(); ?>
<?php include __DIR__ . '/../includes/client-nav.php'; ?>
<?php client_layout_start(['width' => 'wide']); ?>
    <?php client_page_header('Follow-ups', ['subtitle' => 'Auto nudge quiet leads']); ?>

    <?php if ($message): ?><p class="mb-md text-primary font-medium"><?= sanitize($message) ?></p><?php endif; ?>
    <?php if ($error): ?><p class="mb-md text-error font-medium"><?= sanitize($error) ?></p><?php endif; ?>

    <?php if ($bots === []): ?>
    <div class="bg-surface-container-lowest rounded-2xl p-lg border border-outline-variant text-center">
        <p class="text-body-lg mb-md">Connect a bot first.</p>
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

    <p class="mb-lg text-body-md text-on-surface-variant">
        Cron runs every ~15 min via <code class="text-sm">/api/cron.php?key=CRON_SECRET</code>.
        When your last message was sent and the lead hasn't replied within the delay, we send the follow-up once per sequence.
    </p>

    <div class="grid gap-lg lg:grid-cols-2">
        <section class="bg-surface-container-lowest rounded-2xl p-md border border-outline-variant">
            <h2 class="font-title text-title-md mb-md">New follow-up</h2>
            <form method="POST" class="space-y-md">
                <input type="hidden" name="csrf_token" value="<?= sanitize(csrf_token()) ?>"/>
                <input type="hidden" name="action" value="add"/>
                <input type="hidden" name="bot_id" value="<?= $botId ?>"/>
                <div>
                    <label class="block text-label-sm text-on-surface-variant uppercase mb-xs font-label">Name</label>
                    <input name="name" required placeholder="48h gentle nudge" class="w-full h-12 px-md rounded-xl bg-surface-container border border-outline-variant text-body-md"/>
                </div>
                <div>
                    <label class="block text-label-sm text-on-surface-variant uppercase mb-xs font-label">Delay (hours after your last message)</label>
                    <input name="delay_hours" type="number" min="1" max="720" value="48" class="w-full h-12 px-md rounded-xl bg-surface-container border border-outline-variant text-body-md"/>
                </div>
                <div>
                    <label class="block text-label-sm text-on-surface-variant uppercase mb-xs font-label">WhatsApp message</label>
                    <textarea name="message_body" required rows="4" placeholder="Hey! Just checking in — still interested?"
                              class="w-full px-md py-sm rounded-xl bg-surface-container border border-outline-variant text-body-md"></textarea>
                </div>
                <label class="flex items-center gap-xs text-body-md">
                    <input type="checkbox" name="is_active" value="1" checked/>
                    Active
                </label>
                <button type="submit" class="h-12 px-xl rounded-xl bg-primary text-on-primary font-title text-title-md">Add sequence</button>
            </form>
        </section>

        <section class="bg-surface-container-lowest rounded-2xl p-md border border-outline-variant">
            <h2 class="font-title text-title-md mb-md">Sequences (<?= count($sequences) ?>)</h2>
            <?php if ($sequences === []): ?>
            <p class="text-body-md text-on-surface-variant">No follow-ups yet. Add one to recover quiet leads automatically.</p>
            <?php else: ?>
            <div class="space-y-md">
                <?php foreach ($sequences as $seq): ?>
                <form method="POST" class="p-md rounded-xl bg-surface-container-low border border-outline-variant space-y-sm">
                    <input type="hidden" name="csrf_token" value="<?= sanitize(csrf_token()) ?>"/>
                    <input type="hidden" name="action" value="update"/>
                    <input type="hidden" name="bot_id" value="<?= $botId ?>"/>
                    <input type="hidden" name="sequence_id" value="<?= (int) $seq['id'] ?>"/>
                    <input name="name" value="<?= sanitize($seq['name']) ?>" class="w-full h-10 px-sm rounded-lg bg-surface-container-lowest border border-outline-variant text-body-md font-title"/>
                    <div class="flex gap-sm">
                        <input name="delay_hours" type="number" min="1" max="720" value="<?= (int) $seq['delay_hours'] ?>"
                               class="w-24 h-10 px-sm rounded-lg bg-surface-container-lowest border border-outline-variant text-body-md"/>
                        <span class="text-body-md text-on-surface-variant self-center">hours</span>
                    </div>
                    <textarea name="message_body" rows="3" class="w-full px-sm py-xs rounded-lg bg-surface-container-lowest border border-outline-variant text-body-md"><?= sanitize($seq['message_body']) ?></textarea>
                    <label class="flex items-center gap-xs text-body-md">
                        <input type="checkbox" name="is_active" value="1"<?= !empty($seq['is_active']) ? ' checked' : '' ?>/>
                        Active
                    </label>
                    <div class="flex gap-xs">
                        <button type="submit" class="h-10 px-md rounded-lg bg-secondary text-on-secondary text-label-sm font-label">Save</button>
                        <button type="submit" name="action" value="delete" class="h-10 px-md rounded-lg bg-error-container text-on-error-container text-label-sm font-label"
                                onclick="return confirm('Delete this sequence?')">Delete</button>
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
