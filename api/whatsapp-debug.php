<?php
/**
 * WhatsApp Embedded Signup + token debug (logged-in client or admin).
 * Open: /api/whatsapp-debug.php?view=1
 */
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/whatsapp.php';
require_once __DIR__ . '/../includes/whatsapp-oauth.php';
require_once __DIR__ . '/../includes/whatsapp-token.php';
require_once __DIR__ . '/../includes/whatsapp-webhook-log.php';
require_once __DIR__ . '/../includes/integration-settings.php';

$user = require_login();
$clientId = (int) ($user['id']);
if (($user['role'] ?? '') === 'admin' && !empty($_GET['client_id'])) {
    $clientId = (int) $_GET['client_id'];
}

$repair = !empty($_GET['repair']);
$subscribeWaba = !empty($_GET['subscribe_waba']);
$htmlView = !empty($_GET['view']) || (($_SERVER['HTTP_ACCEPT'] ?? '') !== '' && str_contains($_SERVER['HTTP_ACCEPT'], 'text/html'));

if ($repair) {
    whatsapp_sync_embedded_account_to_bots($clientId);
    whatsapp_client_access_token($clientId, true);
}

$account = db_fetch(
    'SELECT * FROM client_whatsapp_accounts
     WHERE client_id = ? AND connection_status = \'active\'
     ORDER BY connected_at DESC LIMIT 1',
    'i',
    [$clientId]
);

$bots = db_fetch_all(
    'SELECT id, name, whatsapp_phone_id, whatsapp_verified, is_active,
            LENGTH(whatsapp_token) AS token_len,
            whatsapp_token_error
     FROM bots WHERE user_id = ? ORDER BY id ASC',
    'i',
    [$clientId]
);

$accountDiag = $account
    ? whatsapp_diagnose_stored_token((string) ($account['business_token'] ?? ''))
    : ['issue' => 'No active client_whatsapp_accounts row'];

$botDiags = [];
foreach ($bots as $bot) {
    $raw = db_fetch('SELECT whatsapp_token FROM bots WHERE id = ?', 'i', [(int) $bot['id']]);
    $botDiags[(int) $bot['id']] = whatsapp_diagnose_stored_token((string) ($raw['whatsapp_token'] ?? ''));
}

$plainToken = whatsapp_client_access_token($clientId, false);
$inboundReady = whatsapp_ensure_client_inbound_ready($clientId);

$metaVerify = ['success' => false, 'message' => 'Skipped'];
$wabaStatus = ['success' => false, 'error' => 'Skipped'];
$webhookSubs = ['success' => false, 'error' => 'Skipped'];
$phoneMeta = ['success' => false, 'error' => 'Skipped'];

if ($plainToken && $account) {
    $phoneId = (string) ($account['phone_number_id'] ?? '');
    if ($phoneId !== '') {
        $metaVerify = verify_whatsapp_credentials($phoneId, $plainToken);
        $phoneMeta = whatsapp_graph_get(
            rawurlencode($phoneId) . '?fields=display_phone_number,verified_name,is_on_biz_app,platform_type',
            $plainToken
        );
    }
    $wabaId = (string) ($account['waba_id'] ?? '');
    if ($wabaId !== '') {
        $wabaStatus = whatsapp_waba_subscription_status($wabaId, $plainToken);
        if ($subscribeWaba && empty($wabaStatus['subscribed'])) {
            $sub = whatsapp_subscribe_waba_to_app($wabaId, $plainToken);
            $wabaStatus['subscribe_attempt'] = $sub;
            $wabaStatus = whatsapp_waba_subscription_status($wabaId, $plainToken);
        }
    }
}

if (function_exists('meta_app_webhook_subscriptions')) {
    $webhookSubs = meta_app_webhook_subscriptions();
}

$webhookLogs = whatsapp_webhook_recent_logs(20);
$recentMessages = db_fetch_all(
    'SELECT direction, status, from_number, to_number, LEFT(message_body, 80) AS preview, created_at
     FROM whatsapp_messages_log WHERE client_id = ? ORDER BY id DESC LIMIT 10',
    'i',
    [$clientId]
);

$coexistenceFields = ['messages', 'history', 'smb_app_state_sync', 'smb_message_echoes'];
$subscribedFields = $webhookSubs['fields'] ?? [];
$missingWebhookFields = array_values(array_diff($coexistenceFields, $subscribedFields));

$configSecretSet = defined('META_APP_SECRET') && trim((string) META_APP_SECRET) !== '';
$storedSecrets = integration_get_stored_secrets();
$adminSecretSet = !empty($storedSecrets['meta_app_secret']);
$metaSecretSource = $adminSecretSet ? 'admin_integrations' : ($configSecretSet ? 'config.local.php' : 'none');
$metaCreds = integration_meta_credentials();
$secretFp = strlen($metaCreds['app_secret'] ?? '') >= 8
    ? substr($metaCreds['app_secret'], 0, 4) . '…' . substr($metaCreds['app_secret'], -4)
    : '(empty)';
$metaAppVerify = whatsapp_meta_verify_app_credentials();

$roundtripOk = false;
$roundtripError = '';
try {
    $probe = 'wa-debug-probe-' . bin2hex(random_bytes(4));
    $enc = encrypt_token($probe);
    $roundtripOk = decrypt_token($enc) === $probe;
    if (!$roundtripOk) {
        $roundtripError = 'encrypt_token → decrypt_token roundtrip failed on this server';
    }
} catch (Throwable $e) {
    $roundtripError = $e->getMessage();
}

$report = [
    'success'        => $plainToken !== false && $plainToken !== '',
    'client_id'      => $clientId,
    'generated_at'   => date('c'),
    'app_url'        => APP_URL,
    'config'         => [
        'meta_app_id'          => integration_config('META_APP_ID'),
        'meta_app_id_effective'=> $metaCreds['app_id'],
        'meta_secret_set'      => ($metaCreds['app_secret'] ?? '') !== '',
        'meta_secret_len'      => strlen((string) ($metaCreds['app_secret'] ?? '')),
        'meta_secret_source'   => $metaSecretSource,
        'meta_secret_fingerprint' => $secretFp,
        'local_config_secret_len' => defined('META_APP_SECRET') ? strlen(trim((string) META_APP_SECRET)) : 0,
        'meta_app_verify'      => $metaAppVerify,
        'admin_integrations_saved' => integration_admin_json_saved(),
        'meta_config_id'       => integration_config('META_CONFIG_ID'),
        'webhook_url'          => rtrim(APP_URL, '/') . '/api/whatsapp-webhook.php',
        'oauth_callback'       => whatsapp_oauth_redirect_uri(),
        'encrypt_key_fp'       => defined('ENCRYPT_KEY') ? substr(hash('sha256', ENCRYPT_KEY), 0, 12) : null,
        'encryption_key_fp'    => defined('ENCRYPTION_KEY') ? substr(hash('sha256', ENCRYPTION_KEY), 0, 12) : null,
        'keys_match'           => defined('ENCRYPT_KEY') && defined('ENCRYPTION_KEY') && ENCRYPT_KEY === ENCRYPTION_KEY,
        'encryption_key_defined' => defined('ENCRYPTION_KEY'),
        'encryption_key_empty'   => defined('ENCRYPTION_KEY') && trim((string) ENCRYPTION_KEY) === '',
        'key_candidates'         => count(encryption_key_candidates()),
        'openssl'              => extension_loaded('openssl'),
        'encrypt_roundtrip_ok' => $roundtripOk,
        'encrypt_roundtrip_error' => $roundtripError,
    ],
    'account'        => $account ? [
        'id'                   => (int) $account['id'],
        'waba_id'              => $account['waba_id'],
        'phone_number_id'      => $account['phone_number_id'],
        'phone_display_number' => $account['phone_display_number'],
        'connected_at'         => $account['connected_at'],
        'token_diagnosis'      => $accountDiag,
    ] : null,
    'bots'           => array_map(static function (array $bot) use ($botDiags): array {
        $id = (int) $bot['id'];

        return [
            'id'                => $id,
            'name'              => $bot['name'],
            'whatsapp_phone_id' => $bot['whatsapp_phone_id'],
            'whatsapp_verified' => (int) ($bot['whatsapp_verified'] ?? 0),
            'is_active'         => (int) ($bot['is_active'] ?? 0),
            'token_len'         => (int) ($bot['token_len'] ?? 0),
            'token_error'       => $bot['whatsapp_token_error'] ?? '',
            'token_diagnosis'   => $botDiags[$id] ?? [],
        ];
    }, $bots),
    'token_readable' => $plainToken !== false && $plainToken !== '',
    'token_prefix'   => ($plainToken && is_string($plainToken)) ? substr($plainToken, 0, 8) . '…' : null,
    'inbound_ready'  => $inboundReady,
    'meta_api'       => [
        'credentials' => $metaVerify,
        'phone'       => $phoneMeta['http_code'] ?? null,
        'phone_data'  => $phoneMeta['data'] ?? null,
        'waba'        => $wabaStatus,
    ],
    'webhooks'       => [
        'subscriptions'    => $webhookSubs,
        'missing_fields'   => $missingWebhookFields,
        'required_fields'  => $coexistenceFields,
        'recent_log_lines' => $webhookLogs,
    ],
    'message_log'    => $recentMessages,
    'fix_steps'      => [],
];

if (empty($metaAppVerify['success'])) {
    $report['fix_steps'][] = 'CRITICAL: Meta App ID + Secret invalid for OAuth: '
        . ($metaAppVerify['error'] ?? 'unknown')
        . ' — Effective App ID: ' . ($metaCreds['app_id'] ?? '')
        . '. Update Admin → Integrations (552479924130015 + secret from Meta) AND config.local.php on server, delete storage/security/config.local.*.php cache.';
}

if (!$account) {
    $report['fix_steps'][] = 'Connect WhatsApp: Bot Setup → Channels → Connect WhatsApp.';
} elseif (!$report['token_readable']) {
    if (!$roundtripOk) {
        $report['fix_steps'][] = 'CRITICAL: Server encryption roundtrip failed.';
        if (!empty($report['config']['encryption_key_empty'])) {
            $report['fix_steps'][] = 'ENCRYPTION_KEY is empty in config.local.php — remove the empty define() line OR set a real 32+ char value.';
        } elseif ((int) ($report['config']['key_candidates'] ?? 0) === 0) {
            $report['fix_steps'][] = 'No ENCRYPTION_KEY found — add a random 32+ character ENCRYPTION_KEY to server config.local.php.';
        } else {
            $report['fix_steps'][] = 'Upload includes/helpers.php + config.php, then add ENCRYPTION_KEY to config.local.php if missing.';
        }
    }
    $report['fix_steps'][] = 'Stored token was encrypted with a DIFFERENT key than today — Disconnect + Connect WhatsApp again AFTER fixing ENCRYPTION_KEY.';
    $report['fix_steps'][] = 'Do NOT change ENCRYPTION_KEY in config.local.php after connecting — or all tokens break.';
    if (empty($accountDiag['encrypt_key_set'])) {
        $report['fix_steps'][] = 'Add ENCRYPT_KEY / ENCRYPTION_KEY to server config.php (see config.example.php).';
    }
} else {
    if (!empty($missingWebhookFields)) {
        $report['fix_steps'][] = 'Meta Developer Console → WhatsApp → Configuration → subscribe webhook fields: ' . implode(', ', $missingWebhookFields);
    }
    if (empty($inboundReady['waba_subscribed'])) {
        $report['fix_steps'][] = 'WABA not subscribed — open this page with ?subscribe_waba=1&view=1 to auto-subscribe.';
    }
    if (empty($metaVerify['success'])) {
        $report['fix_steps'][] = 'Meta rejected token/phone ID: ' . ($metaVerify['message'] ?? 'reconnect WhatsApp.');
    }
    if ($report['fix_steps'] === []) {
        $report['fix_steps'][] = 'Token OK. Test from a different phone (not the business phone). Message Log should show inbound rows.';
    }
}

if ($htmlView) {
    header('Content-Type: text/html; charset=utf-8');
    $title = 'WhatsApp Debug — client #' . $clientId;
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <title><?= sanitize($title) ?></title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 1.5rem; background: #0f1419; color: #e7ecf1; line-height: 1.5; }
        h1 { font-size: 1.35rem; margin-bottom: .25rem; }
        h2 { font-size: 1.05rem; margin: 1.5rem 0 .5rem; color: #7dd3fc; }
        .ok { color: #4ade80; } .bad { color: #f87171; } .warn { color: #fbbf24; }
        .card { background: #1a2332; border: 1px solid #334155; border-radius: 12px; padding: 1rem; margin: .75rem 0; }
        pre { background: #0b1020; padding: .75rem; border-radius: 8px; overflow: auto; font-size: 12px; }
        ul { margin: .5rem 0; padding-left: 1.25rem; }
        a { color: #7dd3fc; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        td, th { border-bottom: 1px solid #334155; padding: .4rem .5rem; text-align: left; vertical-align: top; }
    </style>
</head>
<body>
    <h1><?= sanitize($title) ?></h1>
    <p class="<?= $report['token_readable'] ? 'ok' : 'bad' ?>">
        <?= $report['token_readable'] ? '✅ Token readable' : '❌ Token NOT readable — bot cannot send/reply' ?>
    </p>
    <p class="<?= !empty($metaAppVerify['success']) ? 'ok' : 'bad' ?>">
        Meta App ID + Secret verify: <?= !empty($metaAppVerify['success']) ? '✅ OK (effective App ID ' . sanitize($metaCreds['app_id']) . ')' : '❌ FAILED — ' . sanitize($metaAppVerify['error'] ?? '') ?>
    </p>
    <p class="<?= !empty($report['config']['encrypt_roundtrip_ok']) ? 'ok' : 'bad' ?>">
        Server encrypt roundtrip: <?= !empty($report['config']['encrypt_roundtrip_ok']) ? '✅ OK' : '❌ FAILED' ?>
        <?php if ($roundtripError !== ''): ?>
        <br><small><?= sanitize($roundtripError) ?></small>
        <?php endif; ?>
        <?php if (!empty($report['config']['encryption_key_empty'])): ?>
        <br><small>ENCRYPTION_KEY is defined but EMPTY in config.local.php — remove that line.</small>
        <?php endif; ?>
    </p>
    <p>
        <a href="?view=1&client_id=<?= $clientId ?>">Refresh</a> ·
        <a href="?view=1&client_id=<?= $clientId ?>&repair=1">Run repair</a> ·
        <a href="?view=1&client_id=<?= $clientId ?>&subscribe_waba=1">Subscribe WABA</a> ·
        <a href="/client/whatsapp-settings">WhatsApp Settings</a>
    </p>

    <h2>Fix steps</h2>
    <div class="card">
        <ul>
            <?php foreach ($report['fix_steps'] as $step): ?>
            <li><?= sanitize($step) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>

    <h2>Encryption / config</h2>
    <div class="card"><pre><?= sanitize(json_encode($report['config'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre></div>

    <h2>Account token (client_whatsapp_accounts.business_token)</h2>
    <div class="card"><pre><?= sanitize(json_encode($report['account'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre></div>

    <h2>Bots</h2>
    <div class="card"><pre><?= sanitize(json_encode($report['bots'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre></div>

    <h2>Inbound ready</h2>
    <div class="card"><pre><?= sanitize(json_encode($report['inbound_ready'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre></div>

    <h2>Meta API</h2>
    <div class="card"><pre><?= sanitize(json_encode($report['meta_api'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre></div>

    <h2>Webhooks</h2>
    <div class="card">
        <?php if ($missingWebhookFields !== []): ?>
        <p class="warn">Missing webhook fields: <?= sanitize(implode(', ', $missingWebhookFields)) ?></p>
        <?php else: ?>
        <p class="ok">All required webhook fields subscribed.</p>
        <?php endif; ?>
        <pre><?= sanitize(json_encode($report['webhooks'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
    </div>

    <h2>Recent message log</h2>
    <div class="card">
        <?php if ($recentMessages === []): ?>
        <p class="warn">No rows in whatsapp_messages_log — Meta webhooks may not be arriving yet.</p>
        <?php else: ?>
        <table>
            <tr><th>Dir</th><th>Status</th><th>From</th><th>Preview</th><th>Time</th></tr>
            <?php foreach ($recentMessages as $row): ?>
            <tr>
                <td><?= sanitize($row['direction']) ?></td>
                <td><?= sanitize($row['status']) ?></td>
                <td><?= sanitize($row['from_number']) ?></td>
                <td><?= sanitize($row['preview']) ?></td>
                <td><?= sanitize($row['created_at']) ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
        <?php endif; ?>
    </div>

    <p><small>JSON: <a href="?client_id=<?= $clientId ?>">/api/whatsapp-debug.php?client_id=<?= $clientId ?></a></small></p>
</body>
</html>
    <?php
    exit;
}

header('Content-Type: application/json');
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
