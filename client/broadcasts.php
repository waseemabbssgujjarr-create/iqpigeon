<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/client-shell.php';
require_once __DIR__ . '/../includes/client-header.php';
require_once __DIR__ . '/../includes/broadcasts.php';
require_once __DIR__ . '/../includes/platform-settings.php';

$user = require_login();
require_client_feature('broadcasts');
$userId = (int) $user['id'];
$message = '';
$error = '';

$bots = db_fetch_all('SELECT id, name FROM bots WHERE user_id = ? ORDER BY name ASC', 'i', [$userId]);
$botId = (int) ($_GET['bot_id'] ?? ($bots[0]['id'] ?? 0));
$viewId = (int) ($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf($_POST['csrf_token'] ?? '')) {
    $botId = (int) ($_POST['bot_id'] ?? 0);
    $action = $_POST['action'] ?? '';

    $owned = db_fetch('SELECT id FROM bots WHERE id = ? AND user_id = ?', 'ii', [$botId, $userId]);
    if (!$owned) {
        $error = 'Invalid bot.';
    } elseif ($action === 'create') {
        try {
            $newId = broadcast_create($botId, $userId, $_POST);
            redirect('/client/broadcasts?bot_id=' . $botId . '&id=' . $newId . '&created=1');
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$broadcasts = broadcast_list_for_user($userId, $botId ?: null);
$viewBroadcast = null;
$viewStats = [];
if ($viewId) {
    $viewBroadcast = db_fetch(
        'SELECT * FROM broadcasts WHERE id = ? AND user_id = ?',
        'ii',
        [$viewId, $userId]
    );
    if ($viewBroadcast) {
        $viewStats = broadcast_recipient_stats($viewId);
        $botId = (int) $viewBroadcast['bot_id'];
    }
}

if (!empty($_GET['created'])) {
    $message = 'Broadcast created. Click Send to deliver messages.';
}

$segments = broadcast_segments();
$activeTab = 'broadcasts';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?= page_head('Broadcasts') ?>
</head>
<body class="client-app v2-client bg-background font-body text-on-surface">
<?php client_shell_begin(); ?>
<?php include __DIR__ . '/../includes/client-nav.php'; ?>
<?php client_layout_start(['width' => 'wide']); ?>
    <?php client_page_header('Broadcasts', ['subtitle' => 'Campaigns & templates']); ?>

    <?php if ($message): ?><p class="mb-md text-primary font-medium"><?= sanitize($message) ?></p><?php endif; ?>
    <?php if ($error): ?><p class="mb-md text-error font-medium"><?= sanitize($error) ?></p><?php endif; ?>

    <?php if ($bots === []): ?>
    <div class="bg-surface-container-lowest rounded-2xl p-lg border border-outline-variant text-center">
        <p class="text-body-lg mb-md">Connect a bot before sending broadcasts.</p>
        <a href="/client/onboarding" class="inline-flex h-12 px-lg rounded-xl bg-primary text-on-primary font-title text-title-md items-center">Set up bot</a>
    </div>
    <?php else: ?>

    <form method="get" class="mb-md flex flex-wrap gap-md items-end">
        <div>
            <label class="block text-label-sm text-on-surface-variant uppercase mb-xs font-label">Bot</label>
            <select name="bot_id" data-bot-switch onchange="this.form.submit()" class="h-12 px-md rounded-xl bg-surface-container border border-outline-variant text-body-md min-w-[12rem]">
                <?php foreach ($bots as $b): ?>
                <option value="<?= (int) $b['id'] ?>"<?= (int) $b['id'] === $botId ? ' selected' : '' ?>><?= sanitize($b['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <a href="/client/integrations?bot_id=<?= $botId ?>" class="h-12 px-md rounded-xl border border-outline-variant inline-flex items-center gap-xs text-body-md font-medium">
            <span class="material-symbols-outlined text-lg">sync</span> Store sync
        </a>
    </form>

    <div class="grid gap-lg lg:grid-cols-2">
        <section class="bg-surface-container-lowest rounded-2xl p-md border border-outline-variant">
            <h2 class="font-title text-title-md mb-md">New broadcast</h2>
            <form method="POST" class="space-y-md">
                <input type="hidden" name="csrf_token" value="<?= sanitize(csrf_token()) ?>"/>
                <input type="hidden" name="action" value="create"/>
                <input type="hidden" name="bot_id" value="<?= $botId ?>"/>

                <div>
                    <label class="block text-label-sm text-on-surface-variant uppercase mb-xs font-label">Title</label>
                    <input type="text" name="title" placeholder="Summer sale" class="w-full h-12 px-md rounded-xl bg-surface-container border border-outline-variant text-body-md"/>
                </div>

                <div>
                    <label class="block text-label-sm text-on-surface-variant uppercase mb-xs font-label">Message</label>
                    <textarea name="message_body" rows="4" required class="w-full px-md py-sm rounded-xl bg-surface-container border border-outline-variant text-body-md" placeholder="Hi {{name}} — we have a new offer..."></textarea>
                </div>

                <div class="grid gap-md sm:grid-cols-2">
                    <div>
                        <label class="block text-label-sm text-on-surface-variant uppercase mb-xs font-label">Audience</label>
                        <select name="segment" class="w-full h-12 px-md rounded-xl bg-surface-container border border-outline-variant text-body-md">
                            <?php foreach ($segments as $key => $label): ?>
                            <option value="<?= sanitize($key) ?>"><?= sanitize($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-label-sm text-on-surface-variant uppercase mb-xs font-label">Send mode</label>
                        <select name="send_mode" id="send-mode" class="w-full h-12 px-md rounded-xl bg-surface-container border border-outline-variant text-body-md">
                            <option value="session">Session (free text, 24h window)</option>
                            <option value="template">Template (Meta-approved)</option>
                        </select>
                    </div>
                </div>

                <div id="template-fields" class="grid gap-md sm:grid-cols-2 hidden">
                    <div>
                        <label class="block text-label-sm text-on-surface-variant uppercase mb-xs font-label">Template name</label>
                        <input type="text" name="template_name" placeholder="hello_world" class="w-full h-12 px-md rounded-xl bg-surface-container border border-outline-variant text-body-md"/>
                    </div>
                    <div>
                        <label class="block text-label-sm text-on-surface-variant uppercase mb-xs font-label">Language</label>
                        <input type="text" name="template_lang" value="en" class="w-full h-12 px-md rounded-xl bg-surface-container border border-outline-variant text-body-md"/>
                    </div>
                </div>

                <p class="text-body-md text-on-surface-variant">Outside the 24-hour window, WhatsApp requires an approved template. Session mode skips contacts who haven't messaged recently.</p>

                <button type="submit" class="h-12 px-lg rounded-xl bg-primary text-on-primary font-title text-title-md">Create broadcast</button>
            </form>
        </section>

        <section class="bg-surface-container-lowest rounded-2xl p-md border border-outline-variant">
            <h2 class="font-title text-title-md mb-md">Recent campaigns</h2>
            <?php if ($broadcasts === []): ?>
            <p class="text-body-md text-on-surface-variant">No broadcasts yet.</p>
            <?php else: ?>
            <ul class="divide-y divide-outline-variant">
                <?php foreach ($broadcasts as $bc): ?>
                <li class="py-md">
                    <a href="/client/broadcasts?bot_id=<?= $botId ?>&id=<?= (int) $bc['id'] ?>" class="block active:opacity-80">
                        <div class="flex justify-between gap-sm">
                            <span class="font-medium text-body-md"><?= sanitize($bc['title']) ?></span>
                            <span class="text-label-sm uppercase font-label text-on-surface-variant"><?= sanitize($bc['status']) ?></span>
                        </div>
                        <p class="text-body-md text-on-surface-variant truncate mt-xs"><?= sanitize(mb_substr((string) $bc['message_body'], 0, 80)) ?></p>
                        <p class="text-label-sm text-outline mt-xs"><?= (int) $bc['sent_count'] ?> / <?= (int) $bc['total_recipients'] ?> sent · <?= sanitize($segments[$bc['segment']] ?? $bc['segment']) ?></p>
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </section>
    </div>

    <?php if ($viewBroadcast): ?>
    <section class="mt-lg bg-surface-container-lowest rounded-2xl p-md border border-outline-variant" id="broadcast-detail" data-broadcast-id="<?= (int) $viewBroadcast['id'] ?>">
        <div class="flex flex-wrap justify-between gap-md items-start mb-md">
            <div>
                <h2 class="font-title text-title-md"><?= sanitize($viewBroadcast['title']) ?></h2>
                <p class="text-body-md text-on-surface-variant mt-xs"><?= sanitize($viewBroadcast['message_body']) ?></p>
            </div>
            <?php if ($viewBroadcast['status'] !== 'completed'): ?>
            <button type="button" id="broadcast-send-btn" class="h-12 px-lg rounded-xl bg-primary text-on-primary font-title text-title-md inline-flex items-center gap-xs">
                <span class="material-symbols-outlined">send</span> Send batch
            </button>
            <?php endif; ?>
        </div>

        <div class="grid gap-sm sm:grid-cols-4 mb-md">
            <div class="rounded-xl bg-surface-container p-sm text-center">
                <p class="text-label-sm text-outline">Total</p>
                <p class="text-title-md font-title" id="stat-total"><?= (int) ($viewStats['total'] ?? 0) ?></p>
            </div>
            <div class="rounded-xl bg-surface-container p-sm text-center">
                <p class="text-label-sm text-outline">Sent</p>
                <p class="text-title-md font-title text-primary" id="stat-sent"><?= (int) ($viewStats['sent'] ?? 0) ?></p>
            </div>
            <div class="rounded-xl bg-surface-container p-sm text-center">
                <p class="text-label-sm text-outline">Failed</p>
                <p class="text-title-md font-title text-error" id="stat-failed"><?= (int) ($viewStats['failed'] ?? 0) ?></p>
            </div>
            <div class="rounded-xl bg-surface-container p-sm text-center">
                <p class="text-label-sm text-outline">Pending</p>
                <p class="text-title-md font-title" id="stat-pending"><?= (int) ($viewStats['pending'] ?? 0) ?></p>
            </div>
        </div>

        <p id="broadcast-progress" class="text-body-md text-on-surface-variant"></p>
    </section>
    <?php endif; ?>

    <?php endif; ?>

<?php client_layout_end(); ?>
<?php client_shell_end(); ?>

<script>
(function () {
    var mode = document.getElementById('send-mode');
    var tpl = document.getElementById('template-fields');
    if (mode && tpl) {
        function toggle() {
            tpl.classList.toggle('hidden', mode.value !== 'template');
        }
        mode.addEventListener('change', toggle);
        toggle();
    }

    var btn = document.getElementById('broadcast-send-btn');
    var detail = document.getElementById('broadcast-detail');
    if (!btn || !detail) return;

    var csrf = <?= json_encode(csrf_token()) ?>;
    var running = false;

    function updateStats(stats) {
        if (!stats) return;
        var map = { total: 'stat-total', sent: 'stat-sent', failed: 'stat-failed', pending: 'stat-pending' };
        Object.keys(map).forEach(function (k) {
            var el = document.getElementById(map[k]);
            if (el && stats[k] !== undefined) el.textContent = stats[k];
        });
    }

    async function sendBatch() {
        if (running) return;
        running = true;
        btn.disabled = true;
        var progress = document.getElementById('broadcast-progress');
        progress.textContent = 'Sending…';

        try {
            var res = await fetch('/api/broadcast-run.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ csrf_token: csrf, broadcast_id: parseInt(detail.dataset.broadcastId, 10), batch_size: 25 })
            });
            var data = await res.json();
            if (!data.success) {
                progress.textContent = data.error || 'Send failed';
                return;
            }
            updateStats(data.stats);
            var b = data.batch || {};
            progress.textContent = 'Batch: ' + (b.sent || 0) + ' sent, ' + (b.failed || 0) + ' failed, ' + (b.skipped || 0) + ' skipped.';
            if (b.done) {
                progress.textContent += ' Campaign complete.';
                btn.style.display = 'none';
            } else {
                running = false;
                btn.disabled = false;
                setTimeout(sendBatch, 800);
                return;
            }
        } catch (e) {
            progress.textContent = 'Network error.';
        }
        running = false;
        btn.disabled = false;
    }

    btn.addEventListener('click', sendBatch);
})();
</script>
</body>
</html>
