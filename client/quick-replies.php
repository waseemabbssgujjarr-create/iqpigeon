<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/client-shell.php';
require_once __DIR__ . '/../includes/client-header.php';
require_once __DIR__ . '/../includes/quick-replies.php';

$user = require_login();
$userId = (int) $user['id'];
$message = '';
$error = '';

$bots = db_fetch_all('SELECT id, name FROM bots WHERE user_id = ? ORDER BY name ASC', 'i', [$userId]);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'add') {
            quick_reply_save($userId, $_POST);
            $message = 'Quick reply added.';
        } elseif ($action === 'update') {
            quick_reply_save($userId, $_POST, (int) ($_POST['reply_id'] ?? 0));
            $message = 'Quick reply updated.';
        } elseif ($action === 'delete') {
            quick_reply_delete((int) ($_POST['reply_id'] ?? 0), $userId);
            $message = 'Quick reply removed.';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$replies = quick_replies_for_user($userId, null, false);
$activeTab = 'quickreplies';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?= page_head('Quick Replies') ?>
</head>
<body class="client-app v2-client bg-background font-body text-on-surface">
<?php client_shell_begin(); ?>
<?php include __DIR__ . '/../includes/client-nav.php'; ?>
<?php client_layout_start(['width' => 'wide']); ?>
    <?php client_page_header('Quick Replies', ['subtitle' => 'Saved conversation shortcuts']); ?>

    <?php if ($message): ?><p class="mb-md text-primary font-medium"><?= sanitize($message) ?></p><?php endif; ?>
    <?php if ($error): ?><p class="mb-md text-error font-medium"><?= sanitize($error) ?></p><?php endif; ?>

    <div class="grid gap-lg lg:grid-cols-2">
        <section class="bg-surface-container-lowest rounded-2xl p-md border border-outline-variant">
            <h2 class="font-title text-title-md mb-md">Add quick reply</h2>
            <form method="POST" class="space-y-md">
                <input type="hidden" name="csrf_token" value="<?= sanitize(csrf_token()) ?>"/>
                <input type="hidden" name="action" value="add"/>
                <div>
                    <label class="block text-label-sm text-on-surface-variant uppercase mb-xs font-label">Short label</label>
                    <input name="title" required placeholder="Send pricing" class="w-full h-12 px-md rounded-xl bg-surface-container border border-outline-variant text-body-md"/>
                </div>
                <div>
                    <label class="block text-label-sm text-on-surface-variant uppercase mb-xs font-label">Message</label>
                    <textarea name="message_body" required rows="4" class="w-full px-md py-sm rounded-xl bg-surface-container border border-outline-variant text-body-md"></textarea>
                </div>
                <div>
                    <label class="block text-label-sm text-on-surface-variant uppercase mb-xs font-label">Bot (optional)</label>
                    <select name="bot_id" class="w-full h-12 px-md rounded-xl bg-surface-container border border-outline-variant text-body-md">
                        <option value="">All bots</option>
                        <?php foreach ($bots as $b): ?>
                        <option value="<?= (int) $b['id'] ?>"><?= sanitize($b['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="h-12 px-xl rounded-xl bg-primary text-on-primary font-title text-title-md">Add reply</button>
            </form>
        </section>

        <section class="bg-surface-container-lowest rounded-2xl p-md border border-outline-variant">
            <h2 class="font-title text-title-md mb-md">Saved replies (<?= count($replies) ?>)</h2>
            <?php if ($replies === []): ?>
            <p class="text-body-md text-on-surface-variant">No quick replies yet. Add common answers your team sends often.</p>
            <?php else: ?>
            <div class="space-y-md">
                <?php foreach ($replies as $r): ?>
                <form method="POST" class="p-md rounded-xl bg-surface-container-low border border-outline-variant space-y-sm">
                    <input type="hidden" name="csrf_token" value="<?= sanitize(csrf_token()) ?>"/>
                    <input type="hidden" name="action" value="update"/>
                    <input type="hidden" name="reply_id" value="<?= (int) $r['id'] ?>"/>
                    <input name="title" value="<?= sanitize($r['title']) ?>" class="w-full h-10 px-sm rounded-lg bg-surface-container-lowest border border-outline-variant text-body-md font-title"/>
                    <textarea name="message_body" rows="3" class="w-full px-sm py-xs rounded-lg bg-surface-container-lowest border border-outline-variant text-body-md"><?= sanitize($r['message_body']) ?></textarea>
                    <select name="bot_id" class="w-full h-10 px-sm rounded-lg bg-surface-container-lowest border border-outline-variant text-body-md">
                        <option value="">All bots</option>
                        <?php foreach ($bots as $b): ?>
                        <option value="<?= (int) $b['id'] ?>"<?= (int) ($r['bot_id'] ?? 0) === (int) $b['id'] ? ' selected' : '' ?>><?= sanitize($b['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label class="flex items-center gap-xs text-body-md">
                        <input type="checkbox" name="is_active" value="1"<?= !empty($r['is_active']) ? ' checked' : '' ?>/>
                        Active
                    </label>
                    <div class="flex gap-xs">
                        <button type="submit" class="h-10 px-md rounded-lg bg-secondary text-on-secondary text-label-sm font-label">Save</button>
                        <button type="submit" name="action" value="delete" class="h-10 px-md rounded-lg bg-error-container text-on-error-container text-label-sm font-label" onclick="return confirm('Delete?')">Delete</button>
                    </div>
                </form>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </section>
    </div>
<?php client_layout_end(); ?>
<?php client_shell_end(); ?>
<script src="/assets/js/app.js"></script>
</body>
</html>
