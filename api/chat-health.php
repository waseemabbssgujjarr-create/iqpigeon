<?php
/**
 * Chat widget diagnostics — visit /api/chat-health.php?bot_id=7&run=1
 * DELETE after debugging.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/openai.php';

header('Content-Type: application/json');

$botId = (int) ($_GET['bot_id'] ?? 0);
$runAi = ($_GET['run'] ?? '') === '1';

$out = [
    'ok'     => true,
    'php'    => PHP_VERSION,
    'bot_id' => $botId,
    'checks' => [],
];

try {
    db_connect();
    $out['checks'][] = ['label' => 'Database', 'ok' => true];
} catch (Throwable $e) {
    $out['ok'] = false;
    $out['checks'][] = ['label' => 'Database', 'ok' => false, 'detail' => $e->getMessage()];
}

try {
    ensure_leads_schema();
    ensure_conversations_schema();
    $out['checks'][] = ['label' => 'Schema helpers', 'ok' => true];
} catch (Throwable $e) {
    $out['ok'] = false;
    $out['checks'][] = ['label' => 'Schema helpers', 'ok' => false, 'detail' => $e->getMessage()];
}

require_once __DIR__ . '/../includes/integration-settings.php';

$out['checks'][] = [
    'label'  => 'OpenAI API key',
    'ok'     => integration_openai_chat_key() !== '',
    'detail' => integration_openai_chat_key() !== '' ? 'Set' : 'Missing in Admin → Integrations',
];

if ($botId > 0) {
    try {
        $bot = db_fetch(
            'SELECT id, name, widget_enabled, is_active FROM bots WHERE id = ?',
            'i',
            [$botId]
        );
        $botOk = $bot && (int) $bot['widget_enabled'] === 1 && (int) $bot['is_active'] === 1;
        $out['checks'][] = [
            'label'  => 'Bot #' . $botId,
            'ok'     => $botOk,
            'detail' => $bot
                ? ($bot['name'] ?? '') . ' — widget=' . ($bot['widget_enabled'] ?? '?') . ', active=' . ($bot['is_active'] ?? '?')
                : 'Not found',
        ];
        if (!$botOk) {
            $out['ok'] = false;
        }
    } catch (Throwable $e) {
        $out['ok'] = false;
        $out['checks'][] = ['label' => 'Bot lookup', 'ok' => false, 'detail' => $e->getMessage()];
    }
}

if ($runAi) {
    try {
        $ai = ai_chat([['role' => 'user', 'content' => 'Reply with exactly: OK']], ['max_tokens' => 10]);
        $out['checks'][] = [
            'label'  => 'OpenAI live test',
            'ok'     => !empty($ai['success']),
            'detail' => $ai['content'] ?? ($ai['error'] ?? 'Unknown'),
        ];
        if (empty($ai['success'])) {
            $out['ok'] = false;
        }
    } catch (Throwable $e) {
        $out['ok'] = false;
        $out['checks'][] = ['label' => 'DeepSeek live test', 'ok' => false, 'detail' => $e->getMessage()];
    }
}

echo json_encode($out, JSON_PRETTY_PRINT);
