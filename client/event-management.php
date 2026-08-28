<?php
/**
 * Client portal — Workforce Events integration (WhatsApp event traffic).
 */
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/client-shell.php';
require_once __DIR__ . '/../includes/client-header.php';
require_once __DIR__ . '/../includes/event-management.php';

$user = require_login();
$userId = (int) $user['id'];
$message = '';
$error = '';
$showSecrets = null;

event_mgmt_ensure_schema();

$allowed = event_mgmt_user_allowed($user);
$bots = db_fetch_all('SELECT id, name, whatsapp_verified FROM bots WHERE user_id = ? ORDER BY name ASC', 'i', [$userId]);
$connection = event_mgmt_connection_for_user($userId);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf($_POST['csrf_token'] ?? '')) {
    if (!$allowed) {
        $error = 'An active subscription or trial is required for Event Management.';
    } else {
        try {
            $action = (string) ($_POST['action'] ?? 'save');
            if ($action === 'disable' && $connection) {
                $result = event_mgmt_save_connection($userId, [
                    'bot_id' => (int) $connection['bot_id'],
                    'enabled' => 0,
                    'workforce_base_url' => (string) ($connection['workforce_base_url'] ?? ''),
                ]);
                $connection = $result['connection'];
                $message = 'Event Management integration disabled.';
            } else {
                $result = event_mgmt_save_connection($userId, [
                    'bot_id' => (int) ($_POST['bot_id'] ?? 0),
                    'enabled' => !empty($_POST['enabled']),
                    'workforce_base_url' => trim((string) ($_POST['workforce_base_url'] ?? '')),
                    'rotate_api_key' => !empty($_POST['rotate_api_key']),
                    'rotate_secret' => !empty($_POST['rotate_secret']),
                ]);
                $connection = $result['connection'];
                if (!empty($result['rotated_key']) || !empty($result['rotated_secret'])) {
                    $showSecrets = [
                        'api_key' => $result['rotated_key'] ?? (string) ($connection['api_key'] ?? ''),
                        'webhook_secret' => $result['rotated_secret'] ?? null,
                    ];
                }
                $message = 'Event Management connection saved.';
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
            $connection = event_mgmt_connection_for_user($userId);
        }
    }
}

$stats = ($connection && !empty($connection['id'])) ? event_mgmt_connection_stats((int) $connection['id']) : null;
$ingestUrl = event_mgmt_ingest_url();
$activeTab = 'home';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?= page_head('Event Management') ?>
</head>
<body class="client-app v2-client bg-background font-body text-on-surface">
<?php client_shell_begin(); ?>
<?php include __DIR__ . '/../includes/client-nav.php'; ?>
<?php client_layout_start(); ?>
    <?php client_page_header('Event Management', ['subtitle' => 'Connect Workforce Events to your WhatsApp bot']); ?>

    <?php if ($message): ?><p class="mb-md text-primary font-medium"><?= sanitize($message) ?></p><?php endif; ?>
    <?php if ($error): ?><p class="mb-md text-error font-medium"><?= sanitize($error) ?></p><?php endif; ?>

    <?php if (!$allowed): ?>
    <div class="bg-surface-container-lowest rounded-2xl p-lg border border-outline-variant text-center">
        <p class="text-body-lg mb-md">Event Management is available on an active plan or trial.</p>
        <a href="/client/billing" class="inline-flex h-12 px-lg rounded-xl bg-primary text-on-primary font-title text-title-md items-center">View billing</a>
    </div>
    <?php elseif ($bots === []): ?>
    <div class="bg-surface-container-lowest rounded-2xl p-lg border border-outline-variant text-center">
        <p class="text-body-lg mb-md">Create and connect a WhatsApp bot first.</p>
        <a href="/client/onboarding" class="inline-flex h-12 px-lg rounded-xl bg-primary text-on-primary font-title text-title-md items-center">Set up bot</a>
    </div>
    <?php else: ?>

    <?php if ($showSecrets): ?>
    <div class="mb-lg rounded-2xl border-2 border-primary/40 bg-primary-container/20 p-md">
        <h2 class="font-title text-title-md mb-sm">Copy these into Workforce Events</h2>
        <p class="text-body-md text-on-surface-variant mb-md">Shown once after create/rotate. Store them securely.</p>
        <label class="block text-label-sm uppercase mb-xs">IQPIGEON_INGEST_URL</label>
        <code class="block mb-md p-sm rounded-xl bg-surface-container text-body-sm break-all"><?= sanitize($ingestUrl) ?></code>
        <label class="block text-label-sm uppercase mb-xs">IQPIGEON_API_KEY</label>
        <code class="block mb-md p-sm rounded-xl bg-surface-container text-body-sm break-all"><?= sanitize((string) $showSecrets['api_key']) ?></code>
        <?php if (!empty($showSecrets['webhook_secret'])): ?>
        <label class="block text-label-sm uppercase mb-xs">IQPIGEON_SHARED_SECRET</label>
        <code class="block p-sm rounded-xl bg-surface-container text-body-sm break-all"><?= sanitize((string) $showSecrets['webhook_secret']) ?></code>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($stats && $connection): ?>
    <div class="grid grid-cols-2 md:grid-cols-4 gap-md mb-lg">
        <div class="rounded-2xl bg-surface-container-lowest border border-outline-variant p-md">
            <div class="text-label-sm text-on-surface-variant uppercase">Received today</div>
            <div class="font-headline text-headline-sm mt-xs"><?= (int) ($stats['received_today'] ?? $stats['today'] ?? 0) ?></div>
        </div>
        <div class="rounded-2xl bg-surface-container-lowest border border-outline-variant p-md">
            <div class="text-label-sm text-on-surface-variant uppercase">Pending</div>
            <div class="font-headline text-headline-sm mt-xs"><?= (int) ($stats['pending'] ?? 0) ?></div>
        </div>
        <div class="rounded-2xl bg-surface-container-lowest border border-outline-variant p-md">
            <div class="text-label-sm text-on-surface-variant uppercase">Processed</div>
            <div class="font-headline text-headline-sm mt-xs"><?= (int) ($stats['sent'] ?? 0) ?></div>
        </div>
        <div class="rounded-2xl bg-surface-container-lowest border border-outline-variant p-md">
            <div class="text-label-sm text-on-surface-variant uppercase">Failed</div>
            <div class="font-headline text-headline-sm mt-xs"><?= (int) ($stats['failed'] ?? 0) ?></div>
        </div>
    </div>
    <?php if (!empty($connection['last_error'])): ?>
    <p class="mb-md text-error text-body-md">Last error: <?= sanitize((string) $connection['last_error']) ?></p>
    <?php endif; ?>
    <?php endif; ?>

    <form method="post" class="bg-surface-container-lowest rounded-2xl p-lg border border-outline-variant space-y-md max-w-2xl">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="save">

        <label class="flex items-center gap-sm">
            <input type="checkbox" name="enabled" value="1"<?= ($connection && !empty($connection['enabled'])) ? ' checked' : '' ?>>
            <span class="font-title text-title-md">Enable Event Management</span>
        </label>

        <div>
            <label class="block text-label-sm text-on-surface-variant uppercase mb-xs font-label">WhatsApp bot</label>
            <select name="bot_id" required class="w-full h-12 px-md rounded-xl bg-surface-container border border-outline-variant text-body-md">
                <?php foreach ($bots as $b): ?>
                <option value="<?= (int) $b['id'] ?>"<?= $connection && (int) $connection['bot_id'] === (int) $b['id'] ? ' selected' : '' ?>>
                    <?= sanitize($b['name']) ?><?= !empty($b['whatsapp_verified']) ? '' : ' (WhatsApp not connected)' ?>
                </option>
                <?php endforeach; ?>
            </select>
            <p class="mt-xs text-body-sm text-on-surface-variant">Workforce event traffic is routed through this bot.</p>
        </div>

        <div>
            <label class="block text-label-sm text-on-surface-variant uppercase mb-xs font-label">Workforce base URL (optional callbacks)</label>
            <input type="url" name="workforce_base_url" placeholder="https://events.your-domain.com"
                   value="<?= sanitize((string) ($connection['workforce_base_url'] ?? '')) ?>"
                   class="w-full h-12 px-md rounded-xl bg-surface-container border border-outline-variant text-body-md">
        </div>

        <?php if ($connection): ?>
        <div class="rounded-xl bg-surface-container p-md">
            <div class="text-label-sm uppercase text-on-surface-variant mb-xs">Ingest URL</div>
            <code class="text-body-sm break-all"><?= sanitize($ingestUrl) ?></code>
            <div class="text-label-sm uppercase text-on-surface-variant mt-md mb-xs">API key (prefix)</div>
            <code class="text-body-sm"><?= sanitize(substr((string) $connection['api_key'], 0, 16)) ?>…</code>
            <div class="flex flex-wrap gap-md mt-md">
                <label class="flex items-center gap-sm text-body-sm">
                    <input type="checkbox" name="rotate_api_key" value="1"> Rotate API key
                </label>
                <label class="flex items-center gap-sm text-body-sm">
                    <input type="checkbox" name="rotate_secret" value="1"> Rotate HMAC secret
                </label>
            </div>
        </div>
        <?php else: ?>
        <p class="text-body-sm text-on-surface-variant">Saving for the first time generates your API key and HMAC secret.</p>
        <input type="hidden" name="rotate_secret" value="1">
        <?php endif; ?>

        <div class="flex flex-wrap gap-sm pt-sm">
            <button type="submit" class="h-12 px-lg rounded-xl bg-primary text-on-primary font-title text-title-md">Save connection</button>
            <a href="/client/dashboard" class="h-12 px-lg rounded-xl border border-outline-variant text-on-surface font-title text-title-md inline-flex items-center">Back to dashboard</a>
        </div>
    </form>

    <?php if ($connection && !empty($connection['enabled'])): ?>
    <form method="post" class="mt-md max-w-2xl" onsubmit="return confirm('Disable Event Management?');">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="disable">
        <button type="submit" class="h-11 px-md rounded-xl border border-outline-variant text-on-surface-variant">Disable integration</button>
    </form>
    <?php endif; ?>

    <div class="mt-xl max-w-2xl text-body-md text-on-surface-variant space-y-sm">
        <h2 class="font-title text-title-md text-on-surface">How it works</h2>
        <ol class="list-decimal pl-lg space-y-xs">
            <li>Workforce Events sends event payloads to your ingest URL.</li>
            <li>IQ Pigeon validates the API key and HMAC signature.</li>
            <li>Event traffic is logged and can trigger WhatsApp replies on your bot.</li>
            <li>Connect credentials in Workforce after saving here.</li>
        </ol>
    </div>

    <?php endif; ?>

<?php client_layout_end(); ?>
<?php client_shell_end(); ?>
</body>
</html>
