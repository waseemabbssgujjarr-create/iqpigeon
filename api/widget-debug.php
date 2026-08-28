<?php
/**
 * Widget + AI diagnostics for a bot (logged-in owner only).
 *
 * Examples:
 *   /api/widget-debug.php?bot_id=17
 *   /api/widget-debug.php?bot_id=17&test=1&message=Hello
 *   /api/widget-debug.php?bot_id=17&test=1&message=Hello&ai=1
 */
declare(strict_types=1);

header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/integration-settings.php';
require_once __DIR__ . '/../includes/openai.php';
require_once __DIR__ . '/../includes/bot-knowledge.php';

$botId = (int) ($_GET['bot_id'] ?? $_POST['bot_id'] ?? 0);
$runTest = ($_GET['test'] ?? '') === '1';
$runAi = ($_GET['ai'] ?? '') === '1';
$testMessage = trim((string) ($_GET['message'] ?? $_POST['message'] ?? 'Hi'));

$user = null;
try {
    require_once __DIR__ . '/../includes/auth.php';
    $user = require_login();
} catch (Throwable $e) {
    json_response(['success' => false, 'error' => 'Login required. Open this URL while signed in to IQ Pigeon.'], 401);
}

if ($botId <= 0) {
    json_response(['success' => false, 'error' => 'Missing bot_id'], 400);
}

$bot = db_fetch(
    'SELECT b.*, u.company_name, u.email AS owner_email
     FROM bots b JOIN users u ON u.id = b.user_id
     WHERE b.id = ? AND b.user_id = ?',
    'ii',
    [$botId, (int) $user['id']]
);

if (!$bot) {
    json_response(['success' => false, 'error' => 'Bot not found or not owned by your account.'], 404);
}

$keyFiles = [
    'helpers.php'      => __DIR__ . '/../includes/helpers.php',
    'catalog.php'      => __DIR__ . '/../includes/catalog.php',
    'ai-respond.php'   => __DIR__ . '/ai-respond.php',
    'chat-widget.php'  => __DIR__ . '/chat-widget.php',
    'chat-widget.js'   => __DIR__ . '/../assets/js/chat-widget.js',
];

$fileChecks = [];
foreach ($keyFiles as $label => $path) {
    $fileChecks[$label] = [
        'exists'  => is_readable($path),
        'updated' => is_readable($path) ? date('c', (int) filemtime($path)) : null,
    ];
}

$greetingSamples = ['Hi', 'Hello', 'Hello Hello', 'Hey there', 'What do you offer?'];
$greetingTests = [];
foreach ($greetingSamples as $sample) {
    $greetingTests[$sample] = [
        'is_greeting' => function_exists('message_is_simple_greeting')
            ? message_is_simple_greeting($sample)
            : null,
    ];
}

require_once __DIR__ . '/../includes/catalog.php';

$fallbackSamples = [];
foreach (['Hi', 'Hello Hello', 'What do you offer?'] as $sample) {
    $fallbackSamples[$sample] = human_shop_fallback_reply($bot, $sample, 'error');
}

$out = [
    'success' => true,
    'bot'     => [
        'id'                   => (int) $bot['id'],
        'name'                 => (string) ($bot['name'] ?? ''),
        'rep_name'             => (string) ($bot['rep_name'] ?? ''),
        'widget_enabled'       => (int) ($bot['widget_enabled'] ?? 0),
        'is_active'            => (int) ($bot['is_active'] ?? 0),
        'knowledge_updated_at' => (string) ($bot['knowledge_updated_at'] ?? ''),
        'widget_header_name'   => get_widget_bot_name($bot),
        'rep_from_helper'      => get_bot_rep_name($bot),
        'greeting_template'    => get_demo_greeting($bot),
        'has_knowledge'        => trim((string) ($bot['bot_knowledge'] ?? '')) !== ''
            || trim((string) ($bot['business_model'] ?? '')) !== '',
        'product_count'        => (int) (db_fetch(
            'SELECT COUNT(*) AS cnt FROM bot_products WHERE bot_id = ?',
            'i',
            [$botId]
        )['cnt'] ?? 0),
    ],
    'code' => [
        'message_is_simple_greeting'     => function_exists('message_is_simple_greeting'),
        'conversation_try_greeting_response' => function_exists('conversation_try_greeting_response'),
        'human_shop_fallback_reply'      => function_exists('human_shop_fallback_reply'),
        'files'                          => $fileChecks,
    ],
    'greeting_tests'    => $greetingTests,
    'fallback_previews' => $fallbackSamples,
    'openai_key_set'  => integration_openai_chat_key() !== '',
];

if ($runAi) {
    $ai = ai_chat(
        [['role' => 'user', 'content' => 'Reply with exactly: PING']],
        ['max_tokens' => 8, 'temperature' => 0]
    );
    $out['ai_ping'] = [
        'ok'      => !empty($ai['success']),
        'content' => $ai['content'] ?? null,
        'error'   => $ai['error'] ?? null,
    ];
    if (empty($ai['success'])) {
        $out['ai_ping']['hint'] = 'Widget falls back to generic messages when OpenAI fails. Set key in Admin → Integrations.';
    }
}

if ($runTest) {
    ensure_leads_schema();
    ensure_conversations_schema();

    require_once __DIR__ . '/ai-respond.php';

    $sessionId = 'debug_' . bin2hex(random_bytes(8));
    $externalId = 'widget_' . preg_replace('/[^a-zA-Z0-9_-]/', '', $sessionId);

    $lead = db_fetch(
        'SELECT * FROM leads WHERE bot_id = ? AND external_id = ?',
        'is',
        [$botId, $externalId]
    );

    if (!$lead) {
        $leadId = db_insert(
            'INSERT INTO leads (bot_id, external_id, name, platform, status) VALUES (?, ?, ?, \'widget\', \'new\')',
            'iss',
            [$botId, $externalId, 'Widget Debug']
        );
    } else {
        $leadId = (int) $lead['id'];
        db_execute('DELETE FROM conversations WHERE lead_id = ?', 'i', [$leadId]);
    }

    $result = get_ai_response($leadId, $botId, $testMessage, [
        'locale' => 'en',
    ]);

    $out['live_test'] = [
        'message'    => $testMessage,
        'success'    => !empty($result['success']),
        'reply'      => $result['reply'] ?? null,
        'signals'    => $result['signals'] ?? [],
        'error'      => $result['error'] ?? null,
        'session_id' => $sessionId,
        'lead_id'    => $leadId,
    ];

    if (!empty($result['reply']) && str_contains((string) $result['reply'], 'checking on')) {
        $out['live_test']['problem'] = 'Still using old catalog/error fallback — upload latest helpers.php, catalog.php, ai-respond.php, chat-widget.js';
    }
    if (!empty($result['signals']) && in_array('GREETING', $result['signals'], true)) {
        $out['live_test']['path'] = 'greeting_fast_path (OK)';
    } elseif (!empty($result['signals']) && in_array('FALLBACK', $result['signals'], true)) {
        $out['live_test']['path'] = 'ai_failed_fallback';
    }
}

$out['usage'] = [
    'config'    => rtrim(APP_URL, '/') . '/api/widget-debug.php?bot_id=' . $botId,
    'live_test' => rtrim(APP_URL, '/') . '/api/widget-debug.php?bot_id=' . $botId . '&test=1&message=Hello',
    'ai_ping'   => rtrim(APP_URL, '/') . '/api/widget-debug.php?bot_id=' . $botId . '&ai=1',
];

json_response($out);
