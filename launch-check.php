<?php
/**
 * IQ Pigeon — Launch readiness checker
 *
 * Visit: /launch-check.php?key=YOUR_CRON_SECRET
 * Or log in as admin: /launch-check.php
 *
 * Shows exactly what is done vs what you still need to do (server + Meta Dashboard).
 */
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/integration-settings.php';
require_once __DIR__ . '/includes/whatsapp.php';
require_once __DIR__ . '/includes/whatsapp-oauth.php';
require_once __DIR__ . '/includes/facebook-oauth.php';

// ── Access control ─────────────────────────────────────────────────────────
$key = (string) ($_GET['key'] ?? '');
$accessOk = false;
$viewer = 'guest';

$cronSecret = defined('CRON_SECRET') ? (string) CRON_SECRET : '';
if ($cronSecret !== '' && hash_equals($cronSecret, $key)) {
    $accessOk = true;
    $viewer = 'key';
} elseif (admin_access_key_valid($key)) {
    $accessOk = true;
    $viewer = 'key';
}
if (!$accessOk) {
    $user = get_user();
    if ($user && ($user['role'] ?? '') === 'admin') {
        $accessOk = true;
        $viewer = 'admin';
    }
}

if (!$accessOk) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:2rem;max-width:40rem">';
    echo '<h1>403 — Access denied</h1>';
    echo '<p><strong>Option 1:</strong> Log in as admin, then open <code>/launch-check.php</code></p>';
    echo '<p><strong>Option 2:</strong> <code>/launch-check.php?key=YOUR_CRON_SECRET</code></p>';
    echo '<p><a href="/admin/login">Admin login</a></p>';
    echo '</body></html>';
    exit;
}

const EXPECTED_CONFIG_ID = '1647730086942089';
const EXPECTED_FACEBOOK_LOGIN_CONFIG_ID = '1716394972899725';
const EXPECTED_APP_ID = '552479924130015';

/** @var list<array{id: string, priority: string, label: string, status: string, detail: string, action: string}> */
$checks = [];

/** @var list<array{priority: string, action: string}> */
$todoOnly = [];

function lc_add(
    string $id,
    string $priority,
    string $label,
    string $status,
    string $detail = '',
    string $action = ''
): void {
    global $checks;
    $checks[] = [
        'id'       => $id,
        'priority' => $priority,
        'label'    => $label,
        'status'   => $status,
        'detail'   => $detail,
        'action'   => $action,
    ];
}

function lc_meta_manual(string $action, string $priority = 'critical'): void
{
    global $todoOnly;
    foreach ($todoOnly as $row) {
        if ($row['action'] === $action) {
            return;
        }
    }
    $todoOnly[] = ['priority' => $priority, 'action' => $action];
}

function lc_http_ok(string $url): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'code' => 0, 'error' => 'curl not available'];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_SSL_VERIFYPEER => defined('META_GRAPH_SSL_VERIFY') ? META_GRAPH_SSL_VERIFY : true,
        CURLOPT_NOBODY         => false,
        CURLOPT_USERAGENT      => 'IQPigeon-LaunchCheck/1.0',
    ]);
    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    return ['ok' => $code >= 200 && $code < 400, 'code' => $code, 'error' => $err];
}

function lc_contains_legacy(string $haystack): bool
{
    return preg_match('/aileadssetter|aderalabs\.com|LEGACY_APP_HOSTS|LEGACY_WEBHOOK/i', $haystack) === 1;
}

// ── 1. Domain & config ─────────────────────────────────────────────────────
$appUrl = rtrim((string) (defined('APP_URL') ? APP_URL : ''), '/');

lc_add(
    'domain',
    'critical',
    'Production domain is iqpigeon.com',
    str_contains($appUrl, 'iqpigeon.com') ? 'pass' : 'fail',
    'APP_URL = ' . ($appUrl !== '' ? $appUrl : '(empty)'),
    'Set APP_URL to https://iqpigeon.com in config.local.php'
);

foreach (['LEGACY_APP_HOSTS', 'LEGACY_WEBHOOK_VERIFY_TOKEN', 'LEGACY_REDIRECT_TO_CANONICAL', 'APP_CANONICAL_URL'] as $legacyConst) {
    lc_add(
        'legacy_' . strtolower($legacyConst),
        'warn',
        'No legacy constant: ' . $legacyConst,
        defined($legacyConst) ? 'fail' : 'pass',
        defined($legacyConst) ? 'Still defined — remove from config.local.php' : 'Removed (standalone iqpigeon)',
        defined($legacyConst) ? 'Delete define(\'' . $legacyConst . '\', ...) from config.local.php' : ''
    );
}

$metaAppId = integration_config('META_APP_ID');
lc_add(
    'meta_app_id',
    'critical',
    'Meta App ID',
    $metaAppId === EXPECTED_APP_ID ? 'pass' : ($metaAppId !== '' ? 'warn' : 'fail'),
    'Current: ' . ($metaAppId !== '' ? $metaAppId : '(empty)'),
    $metaAppId !== EXPECTED_APP_ID
        ? 'Admin → Integrations → App ID should be ' . EXPECTED_APP_ID
        : ''
);

$configId = integration_config('META_CONFIG_ID');
lc_add(
    'meta_config_id',
    'critical',
    'Embedded Signup Config ID',
    $configId === EXPECTED_CONFIG_ID ? 'pass' : 'fail',
    'Current: ' . ($configId !== '' ? $configId : '(empty)'),
    $configId !== EXPECTED_CONFIG_ID
        ? 'Set META_CONFIG_ID to ' . EXPECTED_CONFIG_ID . ' in config.local.php AND Admin → Integrations → Save'
        : ''
);

$metaSecret = integration_meta_credentials()['app_secret'] ?? '';
$secretOk = $metaSecret !== '' && !integration_is_placeholder_secret($metaSecret);
lc_add(
    'meta_secret',
    'critical',
    'Meta App Secret configured',
    $secretOk ? 'pass' : 'fail',
    $secretOk ? 'Secret is set' : 'Missing or placeholder',
    'Set META_APP_SECRET in config.local.php or Admin → Integrations → Meta App Secret → Save'
);

$encKey = defined('ENCRYPTION_KEY') ? trim((string) ENCRYPTION_KEY) : '';
lc_add(
    'encryption_key',
    'critical',
    'ENCRYPTION_KEY set (stable)',
    strlen($encKey) >= 32 ? 'pass' : 'fail',
    strlen($encKey) >= 32 ? 'Length OK (' . strlen($encKey) . ' chars)' : 'Too short or empty',
    'Set a random 32+ char ENCRYPTION_KEY in config.local.php — never change after WhatsApp tokens are stored'
);

$manualMode = defined('WHATSAPP_MANUAL_MODE') && WHATSAPP_MANUAL_MODE;
lc_add(
    'manual_mode',
    'critical',
    'Embedded Signup enabled (not manual mode)',
    !$manualMode ? 'pass' : 'fail',
    $manualMode ? 'WHATSAPP_MANUAL_MODE = true' : 'WHATSAPP_MANUAL_MODE = false',
    'Set WHATSAPP_MANUAL_MODE to false in config.php / Admin → Integrations'
);

$verifyToken = integration_config('WEBHOOK_VERIFY_TOKEN');
lc_add(
    'webhook_token',
    'critical',
    'Webhook verify token set',
    $verifyToken !== '' ? 'pass' : 'fail',
    $verifyToken !== '' ? 'Token configured' : 'Empty',
    'Set WEBHOOK_VERIFY_TOKEN in config.local.php and Meta Dashboard'
);

// ── 2. Embedded Signup URL ─────────────────────────────────────────────────
$onboardUrl = whatsapp_embedded_onboard_url();
$extrasJson = whatsapp_embedded_signup_extras_json();
$extras = json_decode($extrasJson, true) ?: [];

$extrasOk = ($extras['featureType'] ?? '') === 'whatsapp_business_app_onboarding'
    && ($extras['sessionInfoVersion'] ?? '') === '3'
    && ($extras['version'] ?? '') === 'v4';

lc_add(
    'signup_extras',
    'critical',
    'Embedded Signup extras (coexistence)',
    $extrasOk ? 'pass' : 'fail',
    'extras = ' . $extrasJson,
    'Must include featureType=whatsapp_business_app_onboarding, sessionInfoVersion=3, version=v4'
);

lc_add(
    'signup_url_config',
    'critical',
    'Embedded Signup URL uses correct Config ID',
    str_contains($onboardUrl, 'config_id=' . EXPECTED_CONFIG_ID) ? 'pass' : 'fail',
    $onboardUrl,
    'Fix Config ID in Admin → Integrations, then Save'
);

lc_add(
    'oauth_launch_url',
    'critical',
    'Connect button uses business.facebook.com onboard (not dialog/oauth)',
    str_contains(whatsapp_oauth_launch_url('test'), 'business.facebook.com/messaging/whatsapp/onboard')
        && str_contains(whatsapp_oauth_launch_url('test'), 'featureType')
        ? 'pass' : 'fail',
    mb_substr(whatsapp_oauth_launch_url('test'), 0, 120) . '…',
    'Deploy latest includes/whatsapp-oauth.php and client/whatsapp-oauth-start.php'
);

$oauthRedirect = whatsapp_oauth_redirect_uri();
$expectedOAuth = $appUrl . '/client/whatsapp-oauth-callback';
lc_add(
    'oauth_redirect',
    'critical',
    'OAuth redirect URI',
    $oauthRedirect === $expectedOAuth ? 'pass' : 'warn',
    $oauthRedirect,
    'Meta → Facebook Login → Settings → Valid OAuth Redirect URIs: ' . $expectedOAuth
);

$graphVer = integration_config('META_GRAPH_API_VERSION');
lc_add(
    'graph_version',
    'warn',
    'Graph API version',
    $graphVer === 'v25.0' ? 'pass' : 'warn',
    'Current: ' . ($graphVer !== '' ? $graphVer : '(default)'),
    $graphVer !== 'v25.0' ? 'Admin → Integrations → set Graph API version to v25.0 → Save' : ''
);

$fbLoginConfigId = facebook_oauth_login_config_id();
lc_add(
    'facebook_login_config_id',
    'critical',
    'Facebook Login for Business config ID (user signup)',
    $fbLoginConfigId === EXPECTED_FACEBOOK_LOGIN_CONFIG_ID ? 'pass' : ($fbLoginConfigId !== '' ? 'warn' : 'fail'),
    $fbLoginConfigId !== '' ? 'config_id=' . $fbLoginConfigId : 'Not set',
    $fbLoginConfigId === EXPECTED_FACEBOOK_LOGIN_CONFIG_ID
        ? ''
        : 'Set FACEBOOK_LOGIN_CONFIG_ID to ' . EXPECTED_FACEBOOK_LOGIN_CONFIG_ID . ' in config.local.php or Admin → Integrations → Login configuration ID'
);

// ── 3. Encryption roundtrip ────────────────────────────────────────────────
$encryptOk = false;
try {
    $sample = 'launch-check-' . time();
    $enc = encrypt_token($sample);
    $dec = decrypt_token($enc);
    $encryptOk = $dec === $sample;
} catch (Throwable $e) {
    $encryptOk = false;
}
lc_add(
    'encrypt_roundtrip',
    'critical',
    'Token encryption roundtrip',
    $encryptOk ? 'pass' : 'fail',
    $encryptOk ? 'encrypt/decrypt OK' : 'Failed — check ENCRYPTION_KEY',
    'Fix ENCRYPTION_KEY in config.local.php; reconnect WhatsApp if key changed'
);

// ── 4. Legal pages (HTTP) ──────────────────────────────────────────────────
$legalPages = [
    'privacy'       => '/privacy.php',
    'terms'         => '/terms.php',
    'data_deletion' => '/data-deletion.php',
];
foreach ($legalPages as $slug => $path) {
    $full = $appUrl . $path;
    $resp = $appUrl !== '' ? lc_http_ok($full) : ['ok' => false, 'code' => 0, 'error' => 'no APP_URL'];
    lc_add(
        'legal_' . $slug,
        'critical',
        'Legal page loads: ' . $path,
        $resp['ok'] ? 'pass' : 'fail',
        $resp['ok'] ? 'HTTP ' . $resp['code'] : ('HTTP ' . $resp['code'] . ($resp['error'] ? ' — ' . $resp['error'] : '')),
        'Ensure ' . $full . ' returns 200. Meta App Basic settings need this URL.'
    );
}

// ── 5. Webhook verify (local simulation) ───────────────────────────────────
$webhookFile = __DIR__ . '/api/whatsapp-webhook.php';
$webhookExists = is_file($webhookFile);
lc_add(
    'webhook_file',
    'critical',
    'Webhook file exists',
    $webhookExists ? 'pass' : 'fail',
    $webhookFile,
    'Upload api/whatsapp-webhook.php to server'
);

if ($webhookExists && $verifyToken !== '' && $appUrl !== '') {
    $challenge = 'launchcheck' . random_int(1000, 9999);
    $verifyUrl = $appUrl . '/api/whatsapp-webhook.php?hub.mode=subscribe&hub.verify_token='
        . rawurlencode($verifyToken) . '&hub.challenge=' . rawurlencode($challenge);
    $wh = lc_http_ok($verifyUrl);
    // lc_http_ok checks 2xx; webhook returns plain text challenge — fetch body
    $body = '';
    if (function_exists('curl_init')) {
        $ch = curl_init($verifyUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 12,
            CURLOPT_SSL_VERIFYPEER => defined('META_GRAPH_SSL_VERIFY') ? META_GRAPH_SSL_VERIFY : true,
        ]);
        $body = (string) curl_exec($ch);
        curl_close($ch);
    }
    $whOk = trim($body) === $challenge;
    lc_add(
        'webhook_live',
        'critical',
        'Webhook verify endpoint (live HTTP test)',
        $whOk ? 'pass' : 'fail',
        $whOk ? 'Returned challenge correctly' : ('Got: ' . mb_substr(trim($body), 0, 80)),
        'Meta → WhatsApp → Configuration → Callback URL: ' . $appUrl . '/api/whatsapp-webhook.php | Verify token: ' . $verifyToken
    );
}

// ── 6. Meta Graph (if credentials OK) ───────────────────────────────────────
if ($secretOk && $metaAppId !== '') {
    $subs = meta_app_webhook_subscriptions();
    if (!empty($subs['success'])) {
        $fields = $subs['fields'] ?? [];
        $required = ['messages', 'history', 'smb_app_state_sync', 'smb_message_echoes'];
        $missing = array_values(array_diff($required, $fields));
        lc_add(
            'meta_webhook_fields',
            'critical',
            'Meta app webhook fields subscribed',
            $missing === [] ? 'pass' : 'warn',
            $missing === [] ? 'All coexistence fields subscribed' : ('Missing: ' . implode(', ', $missing)),
            $missing !== []
                ? 'Meta → WhatsApp → Configuration → Manage webhook fields → subscribe: ' . implode(', ', $missing)
                : ''
        );
    } else {
        lc_add(
            'meta_webhook_api',
            'warn',
            'Could not read Meta webhook subscriptions via API',
            'warn',
            (string) ($subs['error'] ?? $subs['message'] ?? 'unknown'),
            'Verify META_APP_SECRET and app permissions in Meta Dashboard'
        );
    }
}

// ── 7. Admin integrations DB ───────────────────────────────────────────────
try {
    $adminSaved = integration_admin_json_saved();
    lc_add(
        'admin_integrations_saved',
        'warn',
        'Admin saved integration overrides',
        $adminSaved ? 'pass' : 'warn',
        $adminSaved ? 'Settings stored in database' : 'Using config.php defaults only',
        !$adminSaved ? 'Open Admin → Integrations, confirm Config ID ' . EXPECTED_CONFIG_ID . ', click Save' : ''
    );
} catch (Throwable $e) {
    lc_add('admin_integrations_saved', 'warn', 'Admin integrations DB', 'warn', $e->getMessage(), 'Run /migrate.php?key=CRON_SECRET');
}

// ── 8. Critical files ──────────────────────────────────────────────────────
$criticalFiles = [
    'includes/whatsapp-oauth.php',
    'includes/whatsapp-inbound.php',
    'includes/integration-settings.php',
    'includes/billing-settings.php',
    'admin/integrations.php',
    'admin/billing.php',
    'api/whatsapp-debug.php',
    'client/whatsapp-oauth-callback.php',
    'client/whatsapp-settings.php',
];
foreach ($criticalFiles as $rel) {
    $path = __DIR__ . '/' . $rel;
    lc_add(
        'file_' . str_replace(['/', '.'], '_', $rel),
        'critical',
        'File: ' . $rel,
        is_file($path) ? 'pass' : 'fail',
        $path,
        'Upload missing file from iqpigeon/ deployment package'
    );
}

// ── 9. Legacy string scan (config only) ────────────────────────────────────
$configLocal = __DIR__ . '/config.local.php';
if (is_file($configLocal)) {
    $localBody = (string) file_get_contents($configLocal);
    lc_add(
        'config_local_legacy',
        'warn',
        'config.local.php has no aileadssetter references',
        !lc_contains_legacy($localBody) ? 'pass' : 'fail',
        lc_contains_legacy($localBody) ? 'Found legacy hostname or LEGACY_* in config.local.php' : 'Clean',
        'Remove aileadssetter.aderalabs.com and LEGACY_* from server config.local.php'
    );
}

// ── 10. WhatsApp connection (optional) ───────────────────────────────────────
try {
    $connected = db_fetch(
        'SELECT COUNT(*) AS cnt FROM client_whatsapp_accounts WHERE connection_status = \'active\'',
        '',
        []
    );
    $cnt = (int) ($connected['cnt'] ?? 0);
    lc_add(
        'wa_connected',
        'warn',
        'At least one WhatsApp account connected',
        $cnt > 0 ? 'pass' : 'warn',
        $cnt > 0 ? ($cnt . ' active connection(s)') : 'None yet',
        'Client → WhatsApp Settings → Connect with Meta (after Meta App Review permissions approved)'
    );
} catch (Throwable $e) {
    lc_add('wa_connected', 'warn', 'WhatsApp connections', 'warn', 'DB: ' . $e->getMessage(), 'Run migrations');
}

// ── Meta Dashboard — manual items (cannot verify from server) ───────────────
lc_meta_manual(
    'Meta → Review → App Review → submit NOT SUBMITTED permissions: email + manage_app_solution (click Next → Submit). '
    . 'Until approved, Embedded Signup may show "Facebook Login unavailable".',
    'critical'
);
lc_meta_manual(
    'Meta → Alert Inbox — open and resolve all alerts (you had 5 unread).',
    'critical'
);
lc_meta_manual(
    'Meta → Facebook Login for Business → Settings → Data Deletion Request URL: '
    . $appUrl . '/data-deletion.php → Save changes',
    'critical'
);
lc_meta_manual(
    'Meta → Facebook Login for Business → Settings → Deauthorize callback URL: '
    . $appUrl . '/api/meta-deauthorize.php → Save changes',
    'warn'
);
lc_meta_manual(
    'Meta → Facebook Login for Business → Configurations → create a SEPARATE login config (email + public_profile) for user signup — not the WhatsApp Embedded Signup config. Paste Configuration ID in Admin → Integrations → Login configuration ID.',
    'critical'
);
lc_meta_manual(
    'Meta → Facebook Login for Business → Configurations → confirm WhatsApp Embedded Signup Configuration ID '
    . EXPECTED_CONFIG_ID . ' is linked to App ' . EXPECTED_APP_ID,
    'critical'
);
lc_meta_manual(
    'Meta → WhatsApp → Configuration → Callback URL verified: '
    . $appUrl . '/api/whatsapp-webhook.php | Verify token: ' . ($verifyToken !== '' ? $verifyToken : 'YOUR_WEBHOOK_VERIFY_TOKEN'),
    'critical'
);
lc_meta_manual(
    'Meta → App settings → Basic → Privacy, Terms, Data deletion URLs point to iqpigeon.com (not aileadssetter)',
    'critical'
);
lc_meta_manual(
    'After connect: send Hi from a customer phone → expect ~4s typing → greeting reply',
    'warn'
);

// ── Build unified TODO list ──────────────────────────────────────────────────
/** @var list<array{priority: string, source: string, action: string, status: string}> */
$unifiedTodos = [];

foreach ($checks as $c) {
    if ($c['status'] === 'pass') {
        continue;
    }
    if ($c['action'] === '') {
        continue;
    }
    $unifiedTodos[] = [
        'priority' => $c['priority'],
        'source'   => 'Server: ' . $c['label'],
        'action'   => $c['action'],
        'status'   => $c['status'],
    ];
}

foreach ($todoOnly as $row) {
    $unifiedTodos[] = [
        'priority' => $row['priority'],
        'source'   => 'Meta Dashboard (manual)',
        'action'   => $row['action'],
        'status'   => 'manual',
    ];
}

usort($unifiedTodos, static function (array $a, array $b): int {
    $order = ['critical' => 0, 'warn' => 1, 'manual' => 2];
    return ($order[$a['priority']] ?? 9) <=> ($order[$b['priority']] ?? 9);
});

$counts = ['pass' => 0, 'warn' => 0, 'fail' => 0, 'manual' => 0];
foreach ($checks as $c) {
    $counts[$c['status']] = ($counts[$c['status']] ?? 0) + 1;
}
$counts['manual'] = count($todoOnly);

$keyQs = $key !== '' ? 'key=' . rawurlencode($key) . '&' : '';

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1"/>
<title>IQ Pigeon — Launch Check</title>
<link href="/assets/css/app.css" rel="stylesheet"/>
<style>
.lc-wrap { max-width: 920px; margin: 0 auto; padding: 1.5rem; }
.lc-hero { background: linear-gradient(135deg, #1a472a 0%, #4aad36 100%); color: #fff; border-radius: 1rem; padding: 1.25rem 1.5rem; margin-bottom: 1.5rem; }
.lc-hero h1 { font-size: 1.5rem; margin: 0 0 .35rem; }
.lc-summary { display: flex; gap: .75rem; flex-wrap: wrap; margin-bottom: 1.25rem; }
.lc-pill { padding: .45rem .9rem; border-radius: 999px; font-weight: 600; font-size: .8125rem; }
.lc-pill--pass { background: #dcfce7; color: #166534; }
.lc-pill--warn { background: #fef9c3; color: #854d0e; }
.lc-pill--fail { background: #fee2e2; color: #991b1b; }
.lc-pill--manual { background: #dbeafe; color: #1e40af; }
.lc-todo { border: 2px solid #f59e0b; background: #fffbeb; border-radius: 1rem; padding: 1rem 1.25rem; margin-bottom: 1.5rem; }
.lc-todo h2 { font-size: 1.125rem; margin: 0 0 .75rem; color: #92400e; }
.lc-todo ol { margin: 0; padding-left: 1.25rem; }
.lc-todo li { margin-bottom: .65rem; font-size: .9375rem; line-height: 1.45; }
.lc-todo .tag { display: inline-block; font-size: .6875rem; font-weight: 700; text-transform: uppercase; padding: .1rem .4rem; border-radius: .25rem; margin-right: .35rem; vertical-align: middle; }
.lc-todo .tag--critical { background: #fecaca; color: #991b1b; }
.lc-todo .tag--warn { background: #fde68a; color: #854d0e; }
.lc-todo .tag--manual { background: #bfdbfe; color: #1e40af; }
.lc-done { border: 2px solid #86efac; background: #f0fdf4; border-radius: 1rem; padding: 1rem 1.25rem; margin-bottom: 1.5rem; }
.lc-area h2 { font-size: 1rem; margin: 1.25rem 0 .65rem; border-bottom: 1px solid #e5e7eb; padding-bottom: .4rem; }
.lc-row { display: grid; grid-template-columns: 1.5rem 1fr; gap: .65rem; padding: .65rem .75rem; border-radius: .65rem; margin-bottom: .4rem; border: 1px solid #e5e7eb; background: #fff; font-size: .875rem; }
.lc-row--fail { border-color: #fecaca; background: #fef2f2; }
.lc-row--warn { border-color: #fde68a; background: #fffbeb; }
.lc-row--manual { border-color: #bfdbfe; background: #eff6ff; }
.lc-detail { color: #6b7280; margin-top: .2rem; word-break: break-all; }
.lc-action { color: #b45309; margin-top: .35rem; padding: .4rem .55rem; background: #fffbeb; border-radius: .4rem; }
.lc-links { display: flex; flex-wrap: wrap; gap: .5rem; margin: 1rem 0; }
.lc-btn { display: inline-flex; padding: .5rem 1rem; border-radius: .65rem; font-weight: 600; text-decoration: none; font-size: .875rem; }
.lc-btn--primary { background: #4aad36; color: #fff; }
.lc-btn--secondary { border: 1px solid #d1d5db; color: #374151; }
code { background: #f3f4f6; padding: .08rem .3rem; border-radius: .2rem; font-size: .82em; word-break: break-all; }
</style>
</head>
<body class="bg-background font-body text-on-surface min-h-[100dvh]">
<div class="lc-wrap">
    <div class="lc-hero">
        <h1>IQ Pigeon — Launch Check</h1>
        <p style="margin:0;opacity:.95;font-size:.9375rem">Exact remaining steps for go-live · <?= sanitize($viewer) ?> · <?= sanitize($appUrl) ?> · <?= date('Y-m-d H:i:s T') ?></p>
    </div>

    <div class="lc-summary">
        <span class="lc-pill lc-pill--pass">✓ Pass: <?= (int) $counts['pass'] ?></span>
        <span class="lc-pill lc-pill--warn">⚠ Warn: <?= (int) $counts['warn'] ?></span>
        <span class="lc-pill lc-pill--fail">✗ Fail: <?= (int) $counts['fail'] ?></span>
        <span class="lc-pill lc-pill--manual">📋 Meta manual: <?= (int) $counts['manual'] ?></span>
    </div>

    <?php if ($unifiedTodos === []): ?>
    <div class="lc-done">
        <strong>All automated checks passed.</strong> Complete Meta Dashboard smoke test: Connect WhatsApp → send Hi → verify reply.
    </div>
    <?php else: ?>
    <div class="lc-todo">
        <h2>Your to-do list (<?= count($unifiedTodos) ?> items)</h2>
        <ol>
            <?php foreach ($unifiedTodos as $i => $todo): ?>
            <li>
                <span class="tag tag--<?= sanitize($todo['priority']) ?>"><?= sanitize($todo['priority']) ?></span>
                <strong><?= sanitize($todo['source']) ?></strong><br/>
                <?= sanitize($todo['action']) ?>
            </li>
            <?php endforeach; ?>
        </ol>
    </div>
    <?php endif; ?>

    <div class="lc-links">
        <a class="lc-btn lc-btn--primary" href="?<?= $keyQs ?>">Refresh</a>
        <a class="lc-btn lc-btn--secondary" href="/admin/integrations">Admin → Integrations</a>
        <a class="lc-btn lc-btn--secondary" href="/system-check<?= $key !== '' ? '?key=' . rawurlencode($key) : '' ?>">Full system check</a>
        <a class="lc-btn lc-btn--secondary" href="/client/whatsapp-settings">WhatsApp Settings</a>
    </div>

    <div class="lc-area">
        <h2>Reference URLs (copy to Meta Dashboard)</h2>
        <div class="lc-row" style="grid-template-columns:1fr">
            <div><strong>OAuth redirect</strong><br/><code><?= sanitize($oauthRedirect) ?></code></div>
            <div class="lc-detail" style="margin-top:.5rem"><strong>Webhook URL</strong><br/><code><?= sanitize($appUrl) ?>/api/whatsapp-webhook.php</code></div>
            <div class="lc-detail" style="margin-top:.5rem"><strong>Verify token</strong><br/><code><?= sanitize($verifyToken) ?></code></div>
            <div class="lc-detail" style="margin-top:.5rem"><strong>Embedded Signup URL</strong><br/><code><?= sanitize($onboardUrl) ?></code></div>
            <div class="lc-detail" style="margin-top:.5rem"><strong>Config ID</strong> <code><?= sanitize(EXPECTED_CONFIG_ID) ?></code> · <strong>App ID</strong> <code><?= sanitize(EXPECTED_APP_ID) ?></code></div>
        </div>
    </div>

    <div class="lc-area">
        <h2>All checks (detail)</h2>
        <?php
        $areas = [];
        foreach ($checks as $c) {
            $areas[$c['priority']][] = $c;
        }
        $areaLabels = ['critical' => 'Server — critical', 'warn' => 'Server — warnings'];
        foreach ($areaLabels as $prio => $title):
            if (empty($areas[$prio])) {
                continue;
            }
        ?>
        <h3 style="font-size:.9375rem;margin:.75rem 0 .4rem"><?= sanitize($title) ?></h3>
        <?php foreach ($areas[$prio] as $c):
            $icon = $c['status'] === 'pass' ? '✓' : ($c['status'] === 'warn' ? '⚠' : '✗');
            $rowClass = 'lc-row' . ($c['status'] === 'fail' ? ' lc-row--fail' : ($c['status'] === 'warn' ? ' lc-row--warn' : ''));
        ?>
        <div class="<?= $rowClass ?>">
            <span><?= $icon ?></span>
            <div>
                <strong><?= sanitize($c['label']) ?></strong>
                <?php if ($c['detail'] !== ''): ?><div class="lc-detail"><?= sanitize($c['detail']) ?></div><?php endif; ?>
                <?php if ($c['action'] !== '' && $c['status'] !== 'pass'): ?><div class="lc-action"><strong>Fix:</strong> <?= sanitize($c['action']) ?></div><?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endforeach; ?>

        <h3 style="font-size:.9375rem;margin:.75rem 0 .4rem">Meta Dashboard — manual only</h3>
        <?php foreach ($todoOnly as $row): ?>
        <div class="lc-row lc-row--manual">
            <span>📋</span>
            <div><?= sanitize($row['action']) ?></div>
        </div>
        <?php endforeach; ?>
    </div>

    <p class="text-body-sm text-on-surface-variant mt-lg">
        Upload <code>launch-check.php</code> with your iqpigeon deploy. Restrict access in production (admin login or CRON_SECRET key only).
        See also <code>FINAL-LAUNCH-CHECKLIST.md</code>.
    </p>
</div>
</body>
</html>
