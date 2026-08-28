<?php
/**
 * WhatsApp auto-reply diagnostics — why customers get no response.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/whatsapp-reply-debug-log.php';

/**
 * @return list<array{name: string, ok: bool, detail: string, fix?: string}>
 */
function whatsapp_reply_debug_checks(int $botId, int $userId, bool $verifyMeta = true): array
{
    require_once __DIR__ . '/whatsapp.php';
    require_once __DIR__ . '/whatsapp-token.php';
    require_once __DIR__ . '/whatsapp-webhook-log.php';
    require_once __DIR__ . '/turn-schema-lite.php';

    turn_schema_lite_ensure();

    $checks = [];
    $bot = db_fetch('SELECT * FROM bots WHERE id = ? AND user_id = ?', 'ii', [$botId, $userId]);
    if (!$bot) {
        return [[
            'name'   => 'Bot',
            'ok'     => false,
            'detail' => 'Bot #' . $botId . ' not found for this account.',
            'fix'    => 'Open Train or Dashboard and confirm you are logged in as the right client.',
        ]];
    }

    $checks[] = [
        'name'   => 'Bot active',
        'ok'     => (int) ($bot['is_active'] ?? 0) === 1,
        'detail' => (int) ($bot['is_active'] ?? 0) === 1 ? 'Bot is active' : 'Bot is disabled — WhatsApp will not auto-reply.',
        'fix'    => 'Enable the bot in Bot Setup / Dashboard.',
    ];

    $autoReply = (int) ($bot['whatsapp_auto_reply'] ?? 1) === 1;
    $checks[] = [
        'name'   => 'WhatsApp auto-reply',
        'ok'     => $autoReply,
        'detail' => $autoReply ? 'Auto-reply is ON' : 'Auto-reply is OFF — messages are received but not answered.',
        'fix'    => 'WhatsApp Settings → turn on auto-reply.',
    ];

    $humanLayer = !defined('WHATSAPP_HUMAN_LAYER_ENABLED') || WHATSAPP_HUMAN_LAYER_ENABLED;
    $checks[] = [
        'name'   => 'Human tone layer',
        'ok'     => true,
        'detail' => $humanLayer
            ? 'Human/AI layer ON (OpenAI + warm replies)'
            : 'Human layer OFF — customers get simple fallback text only (still auto-replies).',
        'fix'    => 'Set WHATSAPP_HUMAN_LAYER_ENABLED true in config.local.php for AI replies.',
    ];

    if (defined('WHATSAPP_AUTO_REPLY_CORE') && !WHATSAPP_AUTO_REPLY_CORE) {
        $checks[] = [
            'name'   => 'Deprecated WHATSAPP_AUTO_REPLY_CORE',
            'ok'     => false,
            'detail' => 'WHATSAPP_AUTO_REPLY_CORE=false is ignored — upload latest whatsapp-auto-reply-core.php.',
            'fix'    => 'Remove WHATSAPP_AUTO_REPLY_CORE from config or set true. Use WHATSAPP_HUMAN_LAYER_ENABLED instead.',
        ];
    }

    $phoneId = trim((string) ($bot['whatsapp_phone_id'] ?? ''));
    $tokenRaw = (string) ($bot['whatsapp_token'] ?? '');
    $token = $tokenRaw !== '' ? bot_whatsapp_token_plain($tokenRaw) : false;

    $checks[] = [
        'name'   => 'Phone Number ID',
        'ok'     => $phoneId !== '',
        'detail' => $phoneId !== '' ? $phoneId : 'Missing — webhook cannot send replies.',
        'fix'    => 'Connect WhatsApp again (Connect page or Bot Setup).',
    ];

    $checks[] = [
        'name'   => 'Access token',
        'ok'     => $token !== false && $token !== '',
        'detail' => ($token !== false && $token !== '') ? 'Token decrypt OK' : 'Missing or invalid token.',
        'fix'    => 'Reconnect WhatsApp OAuth.',
    ];

    if ($phoneId !== '' && $token && $verifyMeta) {
        $verify = verify_whatsapp_credentials($phoneId, $token);
        $checks[] = [
            'name'   => 'Meta API credentials',
            'ok'     => !empty($verify['success']),
            'detail' => (string) ($verify['message'] ?? 'Unknown'),
            'fix'    => 'Reconnect WhatsApp or fix token in Bot Setup.',
        ];
    } elseif ($phoneId !== '' && $token) {
        $checks[] = [
            'name'   => 'Meta API credentials',
            'ok'     => true,
            'detail' => 'Skipped live verify (lite page load — use OAuth debug for full check).',
        ];
    }

    $conflicts = $phoneId !== '' ? bot_whatsapp_phone_id_conflicts($phoneId, $botId) : [];
    $checks[] = [
        'name'   => 'Phone ID exclusive',
        'ok'     => $conflicts === [],
        'detail' => $conflicts === []
            ? 'Only this bot uses phone_id ' . $phoneId
            : count($conflicts) . ' other bot(s) share this phone_id — wrong business may reply.',
        'fix'    => 'Run repair on this debug page or reconnect WhatsApp.',
    ];

    require_once __DIR__ . '/integration-settings.php';

    $openAi = integration_openai_chat_key();
    if ($openAi === '' && trim((string) ($bot['openai_api_key'] ?? '')) !== '') {
        $openAi = trim((string) $bot['openai_api_key']);
    }
    $checks[] = [
        'name'   => 'OpenAI key',
        'ok'     => $openAi !== '',
        'detail' => $openAi !== '' ? 'API key present' : 'No OpenAI key — AI replies will fail.',
        'fix'    => 'Admin → Integrations → OpenAI chat key.',
    ];

    if ($openAi !== '' && function_exists('integration_openai_api_url')) {
        $apiUrl = integration_openai_api_url();
        $model = function_exists('integration_openai_model') ? integration_openai_model() : 'gpt-4o-mini';
        $checks[] = [
            'name'   => 'OpenAI API URL',
            'ok'     => str_contains($apiUrl, '/chat/completions'),
            'detail' => $apiUrl . ' · model ' . $model,
            'fix'    => 'In config.local.php use OPENAI_API_URL https://api.openai.com/v1/chat/completions (not just /v1).',
        ];
    }

    $stuck = db_fetch(
        'SELECT COUNT(*) AS cnt FROM conversation_turns
         WHERE bot_id = ? AND status IN (\'processing\', \'buffering\')
         AND updated_at <= DATE_SUB(NOW(), INTERVAL 3 MINUTE)',
        'i',
        [$botId]
    );
    $stuckCnt = (int) ($stuck['cnt'] ?? 0);
    $checks[] = [
        'name'   => 'Stuck conversation turns',
        'ok'     => $stuckCnt === 0,
        'detail' => $stuckCnt === 0
            ? 'No turns stuck in processing/buffering > 3 min'
            : $stuckCnt . ' turn(s) stuck — customer may be waiting forever.',
        'fix'    => 'Use Recover stuck replies (below) or api/wa-recover.php — not Force process.',
    ];

    $hung = db_fetch(
        'SELECT id, lead_id, message_count, processing_started_at FROM conversation_turns
         WHERE bot_id = ? AND status = \'processing\'
         AND processing_started_at IS NOT NULL
         AND (ai_response_text IS NULL OR ai_response_text = \'\')
         AND processing_started_at < DATE_SUB(NOW(), INTERVAL 15 SECOND)
         ORDER BY id DESC LIMIT 1',
        'i',
        [$botId]
    );
    if ($hung) {
        $checks[] = [
            'name'   => 'Hung AI generation',
            'ok'     => false,
            'detail' => 'Turn #' . (int) ($hung['id'] ?? 0) . ' started AI at '
                . ($hung['processing_started_at'] ?? '?') . ' (' . (int) ($hung['message_count'] ?? 0) . ' msgs) — never finished.',
            'fix'    => 'Click Force process stuck turns to reset turn #' . (int) ($hung['id'] ?? 0) . ', then send ONE message.',
        ];
    }

    $bloated = db_fetch(
        'SELECT id, message_count, started_at FROM conversation_turns
         WHERE bot_id = ? AND status IN (\'buffering\', \'processing\')
         AND message_count >= 8 ORDER BY id DESC LIMIT 1',
        'i',
        [$botId]
    );
    if ($bloated) {
        $checks[] = [
            'name'   => 'Bloated turn',
            'ok'     => false,
            'detail' => 'Turn #' . (int) ($bloated['id'] ?? 0) . ' has ' . (int) ($bloated['message_count'] ?? 0)
                . ' messages merged — AI keeps restarting and never replies.',
            'fix'    => 'Recover stuck replies (debug page or api/wa-recover.php), then send only ONE message at a time.',
        ];
    }

    $cronOk = defined('CRON_SECRET') && trim((string) CRON_SECRET) !== '';
    $checks[] = [
        'name'   => 'Turn worker (CRON_SECRET)',
        'ok'     => $cronOk,
        'detail' => $cronOk ? 'Worker auth configured' : 'CRON_SECRET missing — async AI worker cannot run.',
        'fix'    => 'Set CRON_SECRET in config.php (same value used by turn-worker.php).',
    ];

    $failed = db_fetch(
        'SELECT COUNT(*) AS cnt FROM conversation_turns
         WHERE bot_id = ? AND status = \'failed\'
         AND processing_completed_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)',
        'i',
        [$botId]
    );
    $failedCnt = (int) ($failed['cnt'] ?? 0);
    if ($failedCnt > 0) {
        $checks[] = [
            'name'   => 'Failed turns (24h)',
            'ok'     => false,
            'detail' => $failedCnt . ' turn(s) failed — AI started but timed out (hung_ai_timeout). Customer may got no reply.',
            'fix'    => 'Upload latest files, run Recover stuck replies, send ONE message, wait 60s.',
        ];
    }

    $hungFailed = db_fetch(
        'SELECT id, lead_id, suppression_reason, processing_completed_at FROM conversation_turns
         WHERE bot_id = ? AND status = \'failed\' AND suppression_reason = \'hung_ai_timeout\'
         AND processing_completed_at >= DATE_SUB(NOW(), INTERVAL 2 HOUR)
         ORDER BY id DESC LIMIT 1',
        'i',
        [$botId]
    );
    if ($hungFailed) {
        $checks[] = [
            'name'   => 'Hung AI generation',
            'ok'     => false,
            'detail' => 'Turn #' . (int) ($hungFailed['id'] ?? 0) . ' failed at '
                . ($hungFailed['processing_completed_at'] ?? '?') . ' — AI never finished.',
            'fix'    => 'OpenAI may be slow or unreachable from server. Force process sends fallback reply.',
        ];
    }

    $lastSendFail = db_fetch(
        'SELECT e.detail_json, e.created_at
         FROM conversation_turn_events e
         INNER JOIN conversation_turns t ON t.id = e.turn_id
         WHERE t.bot_id = ? AND e.event_type = \'RESPONSE_SEND_FAILED\'
         ORDER BY e.id DESC LIMIT 1',
        'i',
        [$botId]
    );
    if ($lastSendFail) {
        $detail = json_decode((string) ($lastSendFail['detail_json'] ?? ''), true);
        $err = is_array($detail) ? (string) ($detail['error'] ?? 'unknown') : 'unknown';
        $checks[] = [
            'name'   => 'WhatsApp outbound send',
            'ok'     => false,
            'detail' => 'Last send failed at ' . ($lastSendFail['created_at'] ?? '?') . ': ' . $err,
            'fix'    => 'Open Full diagnose JSON — check send_test, token, and server outbound HTTPS to graph.facebook.com.',
        ];
    }

    $webhookLines = whatsapp_webhook_recent_logs(5);
    $recentInbound = false;
    foreach ($webhookLines as $line) {
        if (str_contains($line, 'inbound') || str_contains($line, 'INGEST')) {
            $recentInbound = true;
            break;
        }
    }
    $checks[] = [
        'name'   => 'Recent webhook activity',
        'ok'     => $webhookLines !== [],
        'detail' => $webhookLines === []
            ? 'No webhook log lines — Meta may not be hitting your server.'
            : ($recentInbound ? 'Webhook log has recent inbound activity' : 'Webhook log exists but no recent inbound lines'),
        'fix'    => 'Meta Developer → WhatsApp → Configuration → Webhook URL: ' . rtrim(app_url(), '/') . '/api/whatsapp-webhook.php',
    ];

    return $checks;
}

/**
 * @return array{position: string, summary: string, fix: string}
 */
function whatsapp_reply_debug_stuck_position(array $checks): array
{
    foreach ($checks as $c) {
        if (!empty($c['ok'])) {
            continue;
        }
        $name = (string) ($c['name'] ?? '');
        if ($name === 'WhatsApp auto-reply') {
            return [
                'position' => 'auto_reply_off',
                'summary'  => 'WhatsApp auto-reply is turned off.',
                'fix'      => (string) ($c['fix'] ?? ''),
            ];
        }
        if ($name === 'Phone Number ID' || $name === 'Access token' || $name === 'Meta API credentials') {
            return [
                'position' => 'not_connected',
                'summary'  => 'WhatsApp is not connected or token is invalid.',
                'fix'      => (string) ($c['fix'] ?? ''),
            ];
        }
        if ($name === 'Bloated turn') {
            return [
                'position' => 'bloated_turn',
                'summary'  => 'Too many messages merged into one turn — AI generation keeps restarting and never sends.',
                'fix'      => (string) ($c['fix'] ?? ''),
            ];
        }
        if ($name === 'Hung AI generation') {
            return [
                'position' => 'hung_ai',
                'summary'  => 'AI started but never finished — webhook was killed mid-generation (common on cPanel).',
                'fix'      => (string) ($c['fix'] ?? ''),
            ];
        }
        if ($name === 'Turn worker (CRON_SECRET)') {
            return [
                'position' => 'no_worker',
                'summary'  => 'Async AI worker is not configured — replies depend on a slow inline webhook.',
                'fix'      => (string) ($c['fix'] ?? ''),
            ];
        }
        if ($name === 'OpenAI key') {
            return [
                'position' => 'no_openai',
                'summary'  => 'AI cannot generate a reply without an OpenAI key.',
                'fix'      => (string) ($c['fix'] ?? ''),
            ];
        }
        if ($name === 'Stuck conversation turns') {
            return [
                'position' => 'stuck_turns',
                'summary'  => 'Messages arrived but turns are stuck — reply never sent.',
                'fix'      => (string) ($c['fix'] ?? ''),
            ];
        }
        if ($name === 'WhatsApp outbound send') {
            return [
                'position' => 'send_failed',
                'summary'  => 'AI replied in dashboard but Meta rejected or blocked the WhatsApp send.',
                'fix'      => (string) ($c['fix'] ?? ''),
            ];
        }
        if ($name === 'Recent webhook activity') {
            return [
                'position' => 'no_webhook',
                'summary'  => 'Meta is not delivering messages to your server (or log is empty).',
                'fix'      => (string) ($c['fix'] ?? ''),
            ];
        }
    }

    return [
        'position' => 'unknown',
        'summary'  => 'Checks passed but customer still got no reply — inspect turn events and PHP errors below.',
        'fix'      => 'Send a test WhatsApp message, refresh this page within 30 seconds, and read Recent turn events.',
    ];
}

/**
 * @return list<string>
 */
function whatsapp_reply_debug_php_lint(array $relativePaths): array
{
    $root = dirname(__DIR__);
    $errors = [];
    foreach ($relativePaths as $rel) {
        $path = $root . '/' . ltrim($rel, '/');
        if (!is_file($path)) {
            $errors[] = $rel . ' — MISSING on server';
            continue;
        }
        // Never call exec() — disabled on cPanel and crashes the debug page + webhook finalize.
        $src = @file_get_contents($path);
        if ($src === false) {
            $errors[] = $rel . ' — unreadable';
            continue;
        }
        try {
            token_get_all($src, TOKEN_PARSE);
        } catch (ParseError $e) {
            $errors[] = $rel . ' — parse error line ' . $e->getLine() . ': ' . $e->getMessage();
        } catch (Throwable $e) {
            $errors[] = $rel . ' — ' . $e->getMessage();
        }
    }

    return $errors;
}

/**
 * @return array<string, mixed>
 */
function whatsapp_reply_debug_snapshot(int $botId, int $userId): array
{
    require_once __DIR__ . '/whatsapp-webhook-log.php';
    require_once __DIR__ . '/turn-schema-lite.php';

    turn_schema_lite_ensure();

    $events = db_fetch_all(
        'SELECT e.turn_id, e.event_type, e.detail_json, e.created_at
         FROM conversation_turn_events e
         INNER JOIN conversation_turns t ON t.id = e.turn_id
         WHERE t.bot_id = ?
         ORDER BY e.id DESC LIMIT 25',
        'i',
        [$botId]
    ) ?: [];

    $turns = db_fetch_all(
        'SELECT id, lead_id, status, suppression_reason, message_count,
                last_message_at, processing_started_at, processing_completed_at, ai_response_text
         FROM conversation_turns WHERE bot_id = ?
         ORDER BY id DESC LIMIT 10',
        'i',
        [$botId]
    ) ?: [];

    $conversations = db_fetch_all(
        'SELECT c.id, c.lead_id, c.role, LEFT(c.message, 120) AS message, c.created_at
         FROM conversations c
         INNER JOIN leads l ON l.id = c.lead_id
         WHERE l.bot_id = ?
         ORDER BY c.id DESC LIMIT 15',
        'i',
        [$botId]
    ) ?: [];

    $criticalFiles = [
        'includes/whatsapp-auto-reply-core.php',
        'includes/whatsapp-human-layer.php',
        'includes/wa-recover-lite.php',
        'includes/whatsapp-inbound.php',
        'includes/whatsapp-shop-ux.php',
        'includes/conversation-turn-engine.php',
        'includes/catalog.php',
        'includes/commerce-schema.php',
        'api/whatsapp-webhook.php',
        'api/wa-recover.php',
        'api/wa-fallback.php',
        'api/ai-respond.php',
    ];

    $checks = whatsapp_reply_debug_checks($botId, $userId, false);
    $stuck = whatsapp_reply_debug_stuck_position($checks);

    return [
        'generated_at'   => date('c'),
        'bot_id'         => $botId,
        'checks'         => $checks,
        'stuck'          => $stuck,
        'webhook_log'    => whatsapp_webhook_recent_logs(40),
        'turn_events'    => $events,
        'recent_turns'   => $turns,
        'conversations'  => $conversations,
        'php_lint'       => isset($_GET['lint']) && $_GET['lint'] === '1'
            ? whatsapp_reply_debug_php_lint($criticalFiles)
            : [],
        'file_mtiles'    => array_combine(
            $criticalFiles,
            array_map(static function (string $rel): ?string {
                $path = dirname(__DIR__) . '/' . ltrim($rel, '/');
                return is_file($path) ? date('c', (int) filemtime($path)) : null;
            }, $criticalFiles)
        ),
        'debug_log'      => whatsapp_reply_debug_read(20),
    ];
}
