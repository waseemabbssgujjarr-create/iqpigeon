<?php
/**
 * One-time dev cleanup — delete all bots/leads from testing phase.
 *
 * Admin login required. DELETE this file after use.
 * URL: /cleanup-dev-bots.php
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/cleanup-dev-data.php';

$user = require_admin();

$messages = [];
$errors = [];
$preview = dev_cleanup_preview();
$ran = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf($_POST['csrf_token'] ?? '')) {
    $confirm = trim($_POST['confirm_text'] ?? '');
    $action = $_POST['action'] ?? 'bots_only';

    if (strtoupper($confirm) !== 'DELETE') {
        $errors[] = 'Type DELETE in the confirmation box to proceed.';
    } else {
        if ($action === 'full') {
            $result = dev_cleanup_full(
                !empty($_POST['delete_test_clients']),
                !empty($_POST['clear_whatsapp_logs'])
            );
        } else {
            $result = dev_cleanup_all_bots();
            if (!empty($_POST['delete_test_clients'])) {
                $clientResult = dev_cleanup_test_clients();
                $result['messages'] = array_merge($result['messages'], $clientResult['messages']);
                $result['errors'] = array_merge($result['errors'], $clientResult['errors']);
                $result['success'] = $result['success'] && $clientResult['success'];
            }
        }

        $messages = $result['messages'];
        $errors = $result['errors'];
        $ran = $result['success'];
        $preview = dev_cleanup_preview();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?= page_head('Dev Bot Cleanup') ?>
</head>
<body class="bg-background font-body text-on-surface min-h-[100dvh]">
<?php include __DIR__ . '/includes/admin-navigation.php'; ?>

<div class="md:ml-64 pb-24 px-edge-margin max-w-3xl">
    <header class="py-md safe-top">
        <h1 class="font-headline text-headline-mob">Dev Cleanup</h1>
        <p class="text-body-md text-on-surface-variant">
            Remove bots and leads created during development. Client accounts stay unless you choose to remove test users.
        </p>
    </header>

    <?php foreach ($errors as $err): ?>
        <div class="bg-error-container text-on-error-container rounded-xl p-md mb-md text-body-md"><?= sanitize($err) ?></div>
    <?php endforeach; ?>

    <?php foreach ($messages as $msg): ?>
        <div class="bg-primary-container/20 border border-primary text-on-primary-container rounded-xl p-md mb-md text-body-md"><?= sanitize($msg) ?></div>
    <?php endforeach; ?>

    <?php if ($ran): ?>
        <div class="bg-surface-container-lowest rounded-2xl p-lg border border-outline-variant mb-lg">
            <p class="font-title text-title-md text-primary mb-sm">Cleanup complete</p>
            <p class="text-body-md text-on-surface-variant mb-md">
                New signups will create fresh bots. Enable widget on a bot and set demo bot in Admin → Settings when ready.
            </p>
            <a href="/admin/dashboard" class="inline-flex h-12 px-lg rounded-xl bg-primary text-on-primary font-title text-title-md items-center">Back to Dashboard</a>
        </div>
        <p class="text-error font-medium text-body-md">Delete <code>cleanup-dev-bots.php</code> from your server now.</p>
    <?php endif; ?>

    <section class="bg-surface-container-lowest rounded-2xl p-md border border-outline-variant mb-lg">
        <h2 class="font-title text-title-md mb-md">Current data</h2>
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-md mb-md">
            <div class="bg-surface-container-low rounded-xl p-md text-center">
                <p class="text-2xl font-bold"><?= (int) $preview['bot_count'] ?></p>
                <p class="text-label-sm text-outline font-label">Bots</p>
            </div>
            <div class="bg-surface-container-low rounded-xl p-md text-center">
                <p class="text-2xl font-bold"><?= (int) $preview['lead_count'] ?></p>
                <p class="text-label-sm text-outline font-label">Leads</p>
            </div>
            <div class="bg-surface-container-low rounded-xl p-md text-center">
                <p class="text-2xl font-bold"><?= (int) $preview['convo_count'] ?></p>
                <p class="text-label-sm text-outline font-label">Messages</p>
            </div>
            <div class="bg-surface-container-low rounded-xl p-md text-center">
                <p class="text-2xl font-bold"><?= (int) $preview['client_count'] ?></p>
                <p class="text-label-sm text-outline font-label">Clients</p>
            </div>
        </div>

        <?php if ($preview['demo_bot_id'] !== ''): ?>
            <p class="text-body-md text-on-surface-variant mb-md">
                Public demo bot ID: <strong><?= sanitize($preview['demo_bot_id']) ?></strong> (will be cleared)
            </p>
        <?php endif; ?>

        <?php if (!empty($preview['bots'])): ?>
            <div class="max-h-64 overflow-y-auto border border-outline-variant rounded-xl divide-y divide-outline-variant">
                <?php foreach ($preview['bots'] as $bot): ?>
                <div class="p-sm text-body-md flex justify-between gap-sm">
                    <div class="min-w-0">
                        <span class="font-medium">#<?= (int) $bot['id'] ?> <?= sanitize($bot['name']) ?></span>
                        <span class="text-on-surface-variant block truncate"><?= sanitize($bot['company_name'] ?? $bot['client_email']) ?></span>
                    </div>
                    <span class="text-label-sm text-outline shrink-0"><?= (int) $bot['lead_count'] ?> leads</span>
                </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p class="text-body-md text-on-surface-variant">No bots in database.</p>
        <?php endif; ?>
    </section>

    <?php if ((int) $preview['bot_count'] > 0 || !empty($preview['test_clients'])): ?>
    <form method="POST" class="bg-surface-container-lowest rounded-2xl p-md border border-error/40 space-y-md">
        <input type="hidden" name="csrf_token" value="<?= sanitize(csrf_token()) ?>"/>

        <h2 class="font-title text-title-md text-error">Run cleanup</h2>
        <p class="text-body-md text-on-surface-variant">This cannot be undone. Leads and chat history for deleted bots are permanently removed.</p>

        <div class="space-y-sm">
            <label class="flex items-start gap-sm cursor-pointer">
                <input type="radio" name="action" value="bots_only" checked class="mt-1"/>
                <span>
                    <span class="font-title text-title-md block">Delete all bots only</span>
                    <span class="text-body-md text-on-surface-variant">Keeps all client user accounts. They re-enter onboarding.</span>
                </span>
            </label>
            <label class="flex items-start gap-sm cursor-pointer">
                <input type="radio" name="action" value="full" class="mt-1"/>
                <span>
                    <span class="font-title text-title-md block">Full dev reset</span>
                    <span class="text-body-md text-on-surface-variant">All bots + WhatsApp connection logs</span>
                </span>
            </label>
        </div>

        <label class="flex items-center gap-sm cursor-pointer">
            <input type="checkbox" name="delete_test_clients" value="1"/>
            <span class="text-body-md">Also delete test demo client (<?= sanitize(defined('TEST_CLIENT_EMAIL') ? TEST_CLIENT_EMAIL : 'demo@iqpigeon.com') ?>)</span>
        </label>
        <label class="flex items-center gap-sm cursor-pointer ml-6 hidden" id="whatsapp-log-option">
            <input type="checkbox" name="clear_whatsapp_logs" value="1" checked/>
            <span class="text-body-md">Clear WhatsApp message logs</span>
        </label>

        <div>
            <label class="block text-label-sm uppercase mb-xs font-label text-on-surface-variant">Type DELETE to confirm</label>
            <input type="text" name="confirm_text" required autocomplete="off" placeholder="DELETE"
                   class="w-full h-14 px-md rounded-xl bg-surface-container border border-error/30 text-body-md uppercase"/>
        </div>

        <button type="submit" class="w-full h-14 rounded-xl bg-error-container text-on-error-container font-title text-title-md active:scale-95">
            Run Cleanup
        </button>
    </form>
    <?php endif; ?>
</div>

<script src="/assets/js/app.js"></script>
<script>
document.querySelectorAll('input[name="action"]').forEach(r => {
    r.addEventListener('change', () => {
        const full = document.querySelector('input[name="action"][value="full"]')?.checked;
        document.getElementById('whatsapp-log-option')?.classList.toggle('hidden', !full);
        document.getElementById('whatsapp-log-option')?.classList.toggle('ml-6', full);
    });
});
</script>
</body>
</html>
