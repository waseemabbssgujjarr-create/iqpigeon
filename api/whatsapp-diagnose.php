<?php
/**
 * Diagnose WhatsApp bot + webhook setup (logged-in users).
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/whatsapp.php';
require_once __DIR__ . '/../includes/whatsapp-token.php';
require_once __DIR__ . '/../includes/whatsapp-webhook-log.php';
require_once __DIR__ . '/../includes/media-understanding.php';
require_once __DIR__ . '/../includes/platform-training.php';
require_once __DIR__ . '/../includes/bot-knowledge.php';

header('Content-Type: application/json');

$user = require_login();
$botId = (int) ($_GET['bot_id'] ?? $_POST['bot_id'] ?? 0);
$testPhone = preg_replace('/\D/', '', trim($_GET['test_phone'] ?? $_POST['test_phone'] ?? ''));
$subscribeWaba = !empty($_GET['subscribe_waba']);
$repairRouting = !empty($_GET['repair_routing']);

if (!$botId) {
    json_response(['success' => false, 'error' => 'bot_id required'], 400);
}

$bot = db_fetch('SELECT * FROM bots WHERE id = ? AND user_id = ?', 'ii', [$botId, (int) $user['id']]);
if (!$bot) {
    json_response(['success' => false, 'error' => 'Bot not found'], 404);
}

$checks = [];
$phoneId = trim($bot['whatsapp_phone_id'] ?? '');
$tokenRaw = $bot['whatsapp_token'] ?? '';
$token = $tokenRaw !== '' ? bot_whatsapp_token_plain((string) $tokenRaw) : false;

$checks[] = [
    'name'    => 'Bot active',
    'ok'      => (int) ($bot['is_active'] ?? 0) === 1,
    'detail'  => (int) ($bot['is_active'] ?? 0) === 1 ? 'Bot is active' : 'Enable bot on dashboard',
];

$checks[] = [
    'name'    => 'Phone Number ID saved',
    'ok'      => $phoneId !== '',
    'detail'  => $phoneId !== '' ? $phoneId : 'Set in Bot Setup → Channels',
];

$checks[] = [
    'name'    => 'Access token saved',
    'ok'      => $token !== false && $token !== '',
    'detail'  => ($token !== false && $token !== '') ? 'Token decrypt OK' : 'Reconnect WhatsApp in Bot Setup',
];

$verify = ['success' => false, 'message' => 'Skipped'];
if ($phoneId !== '' && $token) {
    $verify = verify_whatsapp_credentials($phoneId, $token);
}
$checks[] = [
    'name'    => 'Meta API credentials',
    'ok'      => !empty($verify['success']),
    'detail'  => $verify['message'] ?? '',
];

if ($phoneId !== '') {
    if ($repairRouting) {
        $released = bot_whatsapp_release_phone_id($botId, $phoneId);
        $checks[] = [
            'name'   => 'Repair routing (release duplicate phone IDs)',
            'ok'     => true,
            'detail' => $released > 0
                ? 'Disconnected ' . $released . ' other bot(s) from this phone_id — only your bot receives messages now.'
                : 'No duplicate bots found — routing already exclusive.',
        ];
    }

    $conflicts = bot_whatsapp_phone_id_conflicts($phoneId, $botId);
    $checks[] = [
        'name'   => 'Phone ID exclusive to this bot',
        'ok'     => $conflicts === [],
        'detail' => $conflicts === []
            ? 'Only bot #' . $botId . ' uses phone_id ' . $phoneId
            : 'CONFLICT: ' . count($conflicts) . ' other bot(s) still share this phone_id — webhook may reply as the wrong business. Add &repair_routing=1 to this URL or reconnect WhatsApp in Bot Setup.',
    ];

    $routed = bot_resolve_by_whatsapp_phone_id($phoneId);
    $routedId = (int) ($routed['id'] ?? 0);
    $checks[] = [
        'name'   => 'Webhook routes to this bot',
        'ok'     => $routedId === $botId,
        'detail' => $routed
            ? 'Inbound messages use bot #' . $routedId . ' "' . ($routed['name'] ?? '') . '" (' . ($routed['company_name'] ?? '') . ')'
            : 'No active bot resolved for phone_id ' . $phoneId,
    ];

    if ($routed) {
        $promptPreview = build_runtime_bot_prompt($routed, (string) ($routed['company_name'] ?? APP_NAME));
        if (preg_match('/Brand \/ business name:\s*(.+)/', $promptPreview, $m)) {
            $checks[] = [
                'name'   => 'AI brand in system prompt',
                'ok'     => stripos($m[1], (string) ($bot['name'] ?? '')) !== false,
                'detail' => 'Prompt brand line: ' . trim($m[1]),
            ];
        }
    }
}

$checks[] = [
    'name'    => 'Webhook URL',
    'ok'      => true,
    'detail'  => APP_URL . '/api/whatsapp-webhook.php',
];

$checks[] = [
    'name'    => 'Webhook verify token',
    'ok'      => WEBHOOK_VERIFY_TOKEN !== '',
    'detail'  => WEBHOOK_VERIFY_TOKEN,
];

$tokenInspect = ($token && is_string($token)) ? whatsapp_inspect_token($token) : [];
$tokenAppId = (string) ($tokenInspect['app_id'] ?? '');
$configAppId = defined('META_APP_ID') ? (string) META_APP_ID : '';
$appIdsMatch = $tokenAppId !== '' && $configAppId !== '' && $tokenAppId === $configAppId;
$appIdsUnknown = $tokenAppId === '';

$checks[] = [
    'name'   => 'Token app matches config META_APP_ID',
    'ok'     => $appIdsUnknown ? null : $appIdsMatch,
    'detail' => $appIdsUnknown
        ? 'Could not verify (fix META_APP_SECRET below first, then reconnect token)'
        : ($appIdsMatch
            ? 'App ID ' . $configAppId
            : 'Token is from app ' . $tokenAppId . ' but config META_APP_ID is ' . $configAppId),
];

$checks[] = [
    'name'   => 'META_APP_SECRET configured',
    'ok'     => defined('META_APP_SECRET') && META_APP_SECRET !== '' && META_APP_SECRET !== 'your_app_secret',
    'detail' => $appIdsMatch
        ? 'Must match App Secret from the SAME Meta app as META_APP_ID (webhook signature verification)'
        : 'Fix META_APP_ID mismatch first, then paste App Secret from that app (Settings → Basic → Show)',
];

$recentWaLead = db_fetch(
    'SELECT id, name, external_id, created_at FROM leads WHERE bot_id = ? AND platform = \'whatsapp\' ORDER BY id DESC LIMIT 1',
    'i',
    [$botId]
);
$checks[] = [
    'name'    => 'Recent WhatsApp lead received',
    'ok'      => $recentWaLead !== null,
    'detail'  => $recentWaLead
        ? 'Lead #' . $recentWaLead['id'] . ' from ' . ($recentWaLead['external_id'] ?? '') . ' at ' . ($recentWaLead['created_at'] ?? '')
        : 'No WhatsApp leads yet — Meta is not delivering inbound messages to your webhook',
];

$webhookLogs = whatsapp_webhook_recent_logs(15);
$inboundPostLogs = array_values(array_filter($webhookLogs, static fn ($line) => str_contains($line, 'Inbound message')));
$metaPostLogs = array_values(array_filter($webhookLogs, static fn ($line) => str_contains($line, 'POST received (meta)')));
$verifyOnlyLogs = array_values(array_filter($webhookLogs, static fn ($line) => str_contains($line, 'Verification OK')));

$expectedWebhook = rtrim(APP_URL, '/') . '/api/whatsapp-webhook.php';

// Simulate Meta POST with valid signature (proves server accepts POST + secret is correct)
$postSelfOk = false;
$postSelfDetail = '';
if (defined('META_APP_SECRET') && META_APP_SECRET !== '') {
    $samplePayload = json_encode([
        'object' => 'whatsapp_business_account',
        'entry'  => [],
    ]);
    $sig = 'sha256=' . hash_hmac('sha256', $samplePayload, META_APP_SECRET);
    $ch = curl_init($expectedWebhook);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $samplePayload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'X-Hub-Signature-256: ' . $sig,
            'X-AILeads-Diagnose: 1',
        ],
        CURLOPT_TIMEOUT        => 12,
    ]);
    $postBody = curl_exec($ch);
    $postCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $postSelfOk = $postCode === 200 && trim((string) $postBody) === 'OK';
    $postSelfDetail = $postSelfOk
        ? 'Server accepts signed POST (HTTP 200) — META_APP_SECRET matches webhook verifier'
        : 'Signed POST failed HTTP ' . $postCode . ' body: ' . substr((string) $postBody, 0, 60);
    $webhookLogs = whatsapp_webhook_recent_logs(15);
    $inboundPostLogs = array_values(array_filter($webhookLogs, static fn ($line) => str_contains($line, 'Inbound message')));
    $metaPostLogs = array_values(array_filter($webhookLogs, static fn ($line) => str_contains($line, 'POST received (meta)')));
}

$verifyTest = '';
$verifyOk = false;
$verifyUrl = $expectedWebhook
    . '?hub.mode=subscribe&hub.verify_token=' . urlencode(WEBHOOK_VERIFY_TOKEN)
    . '&hub.challenge=diagnose_ok';
$ch = curl_init($verifyUrl);
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12]);
$verifyTest = curl_exec($ch);
curl_close($ch);
$verifyOk = trim((string) $verifyTest) === 'diagnose_ok';

$checks[] = [
    'name'   => 'Webhook URL reachable (GET verify)',
    'ok'     => $verifyOk,
    'detail' => $verifyOk
        ? 'Server responds correctly to Meta verification challenge'
        : 'GET verify failed — response: ' . substr((string) $verifyTest, 0, 80),
];

$metaSubs = meta_app_webhook_subscriptions();
$metaCallback = trim($metaSubs['callback_url'] ?? '');
$metaFields = $metaSubs['fields'] ?? [];
$secretInvalid = !empty($metaSubs['error']) && str_contains(strtolower($metaSubs['error']), 'invalid oauth access token signature');

if ($secretInvalid) {
    $checks[] = [
        'name'   => 'META_APP_SECRET matches META_APP_ID',
        'ok'     => false,
        'detail' => 'WRONG App Secret in config.php for app ' . $configAppId
            . '. Meta says: Invalid OAuth access token signature. '
            . 'Open Meta App → Settings → Basic → App Secret → Show → copy exactly into META_APP_SECRET (no spaces).',
    ];
}

$callbackMatches = $metaCallback !== '' && rtrim($metaCallback, '/') === rtrim($expectedWebhook, '/');
$messagesSubscribed = in_array('messages', $metaFields, true);

$checks[] = [
    'name'   => 'Meta registered callback URL',
    'ok'     => $secretInvalid ? false : $callbackMatches,
    'detail' => $secretInvalid
        ? 'Cannot read Meta — fix META_APP_SECRET first, then set Callback URL on WhatsApp → Configuration'
        : ($metaCallback !== ''
            ? ($callbackMatches
                ? $metaCallback
                : 'Meta has "' . $metaCallback . '" but expected "' . $expectedWebhook . '"')
            : 'Not registered — WhatsApp → Configuration → Edit → paste Callback URL → Verify and save'),
];

$checks[] = [
    'name'   => 'Meta subscribed to messages field',
    'ok'     => $secretInvalid ? false : $messagesSubscribed,
    'detail' => $secretInvalid
        ? 'Fix META_APP_SECRET first'
        : ($messagesSubscribed
            ? 'messages subscribed on Meta'
            : 'Configuration → Manage → toggle messages ON → Subscribe'),
];

$checks[] = [
    'name'   => 'Webhook accepts signed POST',
    'ok'     => $postSelfOk,
    'detail' => $postSelfDetail !== '' ? $postSelfDetail : 'Skipped',
];

$checks[] = [
    'name'    => 'Meta delivered real message POST',
    'ok'      => $metaPostLogs !== [] || $inboundPostLogs !== [],
    'detail'  => $inboundPostLogs !== []
        ? implode(' | ', array_slice($inboundPostLogs, -2))
        : ($metaPostLogs !== []
            ? implode(' | ', array_slice($metaPostLogs, -2))
            : 'No real Meta message yet. Send Hi from +92 300 4522663 to +1 555 100 8437 or click Test on messages in Meta.'),
];

$wabaIds = ($token && is_string($token)) ? whatsapp_waba_ids_from_token($token) : [];
$wabaStatus = null;
$wabaSubscribeResult = null;
if ($wabaIds !== [] && $token) {
    $wabaStatus = whatsapp_waba_subscription_status($wabaIds[0], $token);
    $checks[] = [
        'name'   => 'WABA subscribed to app (inbound webhooks)',
        'ok'     => !empty($wabaStatus['subscribed']),
        'detail' => !empty($wabaStatus['subscribed'])
            ? 'WABA ' . $wabaIds[0] . ' is linked to app ' . $configAppId
            : 'WABA ' . $wabaIds[0] . ' NOT subscribed — add ?subscribe_waba=1 to this diagnose URL to fix',
    ];
    if ($subscribeWaba && empty($wabaStatus['subscribed'])) {
        $wabaSubscribeResult = whatsapp_subscribe_waba_to_app($wabaIds[0], $token);
        $wabaStatus = whatsapp_waba_subscription_status($wabaIds[0], $token);
    }
} else {
    $checks[] = [
        'name'   => 'WABA subscribed to app (inbound webhooks)',
        'ok'     => null,
        'detail' => 'Could not detect WABA ID from token — reconnect WhatsApp in Bot Setup',
    ];
}

$sendTest = null;
if ($testPhone !== '' && $phoneId !== '' && $token && !empty($verify['success'])) {
    $sendTest = send_whatsapp_message(
        $verify['phone_id'] ?? $phoneId,
        $token,
        $testPhone,
        'IQ Pigeon test — if you see this, outbound WhatsApp works. Reply Hi to test the bot.'
    );
}

$mediaTest = media_understanding_self_test();
$checks[] = [
    'name'   => 'Voice & image (OpenAI)',
    'ok'     => $mediaTest['ok'],
    'detail' => $mediaTest['detail'],
];

json_response([
    'success'       => true,
    'bot_id'        => $botId,
    'checks'        => $checks,
    'all_ok'        => !in_array(false, array_filter(array_column($checks, 'ok'), static fn ($v) => $v !== null), true),
    'send_test'     => $sendTest,
    'webhook_logs'      => $webhookLogs,
    'inbound_post_logs' => $inboundPostLogs,
    'meta_post_logs'    => $metaPostLogs,
    'waba_ids'          => $wabaIds,
    'waba_status'       => $wabaStatus,
    'waba_subscribe'    => $wabaSubscribeResult,
    'fix_inbound_url'   => APP_URL . '/api/whatsapp-diagnose.php?bot_id=' . $botId . '&subscribe_waba=1&test_phone=' . urlencode($testPhone),
    'fix_routing_url'   => APP_URL . '/api/whatsapp-diagnose.php?bot_id=' . $botId . '&repair_routing=1',
    'meta_subscriptions'=> $metaSubs,
    'webhook_self_test' => ['verify_ok' => $verifyOk, 'expected_url' => $expectedWebhook],
    'webhook_setup' => [
        'critical' => $appIdsMatch
            ? 'If messages subscribed but Hi still silent, check webhook_logs for REJECTED invalid signature'
            : 'Fix config.php: META_APP_ID + META_APP_SECRET must be from the SAME app where WhatsApp + webhook messages is subscribed (see screenshot URL app ID)',
        'your_webhook_app_id_from_url' => 'If Meta URL shows .../apps/1799697848820581/... use THAT app\'s ID and secret in config.php',
        'step1'    => 'Meta App → WhatsApp → Configuration',
        'step2'    => 'Callback URL: ' . APP_URL . '/api/whatsapp-webhook.php',
        'step3'    => 'Verify token: ' . WEBHOOK_VERIFY_TOKEN,
        'step4'    => 'Manage → messages → Subscribed (you did this ✓)',
        'step5'    => 'config.php META_APP_ID + META_APP_SECRET = same app as token & webhook',
        'step6'    => 'Send Hi, reload diagnose — webhook_logs should show POST received (not REJECTED)',
    ],
    'config_app_id' => $configAppId,
    'token_app_id'  => $tokenAppId !== '' ? $tokenAppId : 'unknown — reconnect token from API Setup',
]);
