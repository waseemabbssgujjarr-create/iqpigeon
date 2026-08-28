<?php
/**
 * Lightweight turn worker — no heavy turn_engine_process_turn (avoids cPanel 503).
 *
 * GET  ?key=...           — health (instant JSON, no heavy includes)
 * GET  ?key=...&dry=1     — SELECT-only recover trace (no Graph send, no turn updates)
 * GET  ?key=...&run=1     — Manual GET recover for due leads (lite path)
 * POST {"key":"...","lead_ids":[102]} — async worker from webhook
 */
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
ignore_user_abort(true);
@set_time_limit(120);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/turn-schema-lite.php';
require_once __DIR__ . '/../includes/wa-recover-lite.php';
require_once __DIR__ . '/../includes/whatsapp-webhook-log.php';

$input = json_decode(file_get_contents('php://input') ?: '{}', true);
if (!is_array($input)) {
    $input = [];
}

$key = (string) ($input['key'] ?? $_GET['key'] ?? '');
$expected = defined('CRON_SECRET') ? (string) CRON_SECRET : '';

if ($expected === '' || !hash_equals($expected, $key)) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

$leadIds = $input['lead_ids'] ?? [];
if (!is_array($leadIds)) {
    $leadIds = [];
}
$leadIds = array_values(array_unique(array_filter(array_map('intval', $leadIds))));

$eventId = trim((string) ($input['event_id'] ?? $_GET['event_id'] ?? ''));
if ($eventId !== '') {
    $GLOBALS['wa_webhook_event_id'] = $eventId;
}
$GLOBALS['wa_webhook_t0'] = $GLOBALS['wa_webhook_t0'] ?? microtime(true);

$botId = (int) ($input['bot_id'] ?? $_GET['bot_id'] ?? 0);
$dryRun = (($input['dry'] ?? $_GET['dry'] ?? '') === '1' || ($input['dry'] ?? '') === true);

function turn_worker_turn_counts(): array
{
    turn_schema_lite_ensure();

    $buffering = db_fetch('SELECT COUNT(*) AS c FROM conversation_turns WHERE status = \'buffering\'', '', []);
    $due = db_fetch(
        'SELECT COUNT(*) AS c FROM conversation_turns WHERE status = \'buffering\' AND finalize_after <= NOW()',
        '',
        []
    );
    $cancelled = db_fetch(
        'SELECT COUNT(*) AS c FROM conversation_turns WHERE status = \'cancelled\'',
        '',
        []
    );
    $needsReply = db_fetch(
        'SELECT COUNT(DISTINCT t.lead_id) AS c FROM conversation_turns t
         WHERE NOT EXISTS (
            SELECT 1 FROM conversation_turn_events e
            WHERE e.turn_id = t.id AND e.event_type = \'RESPONSE_SENT\'
         )
         AND EXISTS (SELECT 1 FROM conversation_turn_messages ctm WHERE ctm.turn_id = t.id)',
        '',
        []
    );

    return [
        'buffering'    => (int) ($buffering['c'] ?? 0),
        'due'          => (int) ($due['c'] ?? 0),
        'cancelled'    => (int) ($cancelled['c'] ?? 0),
        'needs_reply'  => (int) ($needsReply['c'] ?? 0),
        'processing'   => (int) (db_fetch('SELECT COUNT(*) AS c FROM conversation_turns WHERE status = \'processing\'', '', [])['c'] ?? 0),
    ];
}

$runNow = $_SERVER['REQUEST_METHOD'] === 'POST'
    || (($_GET['run'] ?? '') === '1' || ($_GET['run'] ?? '') === 'true');

if ($_SERVER['REQUEST_METHOD'] === 'GET' && !$runNow) {
    header('Content-Type: application/json; charset=utf-8');
    $payload = [
        'success'         => true,
        'health'          => 'ok',
        'lite'            => true,
        'time'            => date('c'),
        'turns'           => turn_worker_turn_counts(),
        'app_url'         => defined('APP_URL') ? APP_URL : null,
        'cron_secret_set' => $expected !== '',
        'hint'            => 'Add &run=1 to process stuck turns (lite path, no 503).',
    ];
    if (($_GET['debug'] ?? '') === '1') {
        $root = dirname(__DIR__);
        $recover = is_readable($root . '/includes/wa-recover-lite.php')
            ? (string) file_get_contents($root . '/includes/wa-recover-lite.php')
            : '';
        $webhook = is_readable($root . '/api/whatsapp-webhook.php')
            ? (string) file_get_contents($root . '/api/whatsapp-webhook.php')
            : '';
        $engine = is_readable($root . '/includes/conversation-turn-engine.php')
            ? (string) file_get_contents($root . '/includes/conversation-turn-engine.php')
            : '';
        $core = is_readable($root . '/includes/whatsapp-auto-reply-core.php')
            ? (string) file_get_contents($root . '/includes/whatsapp-auto-reply-core.php')
            : '';
        $waitFn = strpos($engine, 'function turn_engine_webhook_wait_quiet');
        $waitSrc = $waitFn !== false ? substr($engine, $waitFn, 900) : '';
        $payload['debug'] = [
            'files' => [
                'webhook_mtime'            => is_file($root . '/api/whatsapp-webhook.php')
                    ? date('c', (int) filemtime($root . '/api/whatsapp-webhook.php'))
                    : null,
                'engine_mtime'             => is_file($root . '/includes/conversation-turn-engine.php')
                    ? date('c', (int) filemtime($root . '/includes/conversation-turn-engine.php'))
                    : null,
                'core_mtime'               => is_file($root . '/includes/whatsapp-auto-reply-core.php')
                    ? date('c', (int) filemtime($root . '/includes/whatsapp-auto-reply-core.php'))
                    : null,
                'recover_mtime'            => is_file($root . '/includes/wa-recover-lite.php')
                    ? date('c', (int) filemtime($root . '/includes/wa-recover-lite.php'))
                    : null,
                'already_replied_per_turn' => str_contains($recover, 'no unanswered inbound turn'),
                'recover_dry_inspect'      => str_contains($recover, 'function wa_recover_dry_inspect'),
                'worker_send_pipeline'     => str_contains(is_readable($root . '/api/turn-worker.php') ? (string) file_get_contents($root . '/api/turn-worker.php') : '', 'turn_engine_send_leads_now'),
                'webhook_dup_not_requeued' => str_contains($webhook, 'DUPLICATE_REQUEUE_SEND'),
                'short_quiet_wait'         => str_contains($engine, 'Silent wait until 7s after last inbound')
                    && str_contains($engine, '$maxWaitMs = min(18000'),
                'wait_quiet_no_typing'     => str_contains($waitSrc, 'Silent wait')
                    && !str_contains($waitSrc, 'whatsapp_send_typing_indicator'),
                'webhook_openai'           => str_contains($core, "'path' => 'webhook_openai'"),
                'webhook_mind'             => str_contains($core, 'function wa_webhook_mind_reply')
                    && str_contains($core, "'path' => 'webhook_mind'")
                    && str_contains($engine, "\$GLOBALS['wa_skip_openai'] = true"),
                'type_before_compose'      => str_contains($engine, 'whatsapp_send_typing_indicator($phoneId, $token, $waId)'),
                'webhook_instant_reply'    => str_contains($core, 'function wa_webhook_instant_reply'),
                'send_before_meta_ack'     => str_contains($webhook, 'function wa_webhook_ack_meta')
                    && str_contains($webhook, 'fastcgi_finish_request')
                    && str_contains($engine, 'function turn_engine_send_leads_now'),
            ],
        ];
    }
    if ($dryRun) {
        $payload['dry_run'] = wa_recover_dry_inspect($botId);
        $payload['hint'] = 'dry=1 is SELECT only. No Graph send. No turn updates.';
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

$asyncAck = $_SERVER['REQUEST_METHOD'] === 'POST';
if ($asyncAck) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success'  => true,
        'accepted' => true,
        'lite'     => true,
        'leads'    => $leadIds,
        'time'     => date('c'),
    ], JSON_UNESCAPED_UNICODE);
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } elseif (function_exists('litespeed_finish_request')) {
        litespeed_finish_request();
    } else {
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        @flush();
    }
}

if ($asyncAck && function_exists('whatsapp_webhook_log_event')) {
    whatsapp_webhook_log_event('Worker accepted', [
        'request_leads' => $leadIds,
        'leads'         => $leadIds,
        'dry'           => $dryRun,
        'stage'         => 'worker_start',
    ]);
}

if ($dryRun) {
    $inspect = wa_recover_dry_inspect($botId);
    if (function_exists('whatsapp_webhook_log_event')) {
        whatsapp_webhook_log_event('Worker dry run (no send)', [
            'needing_reply' => $inspect['needing_reply_lead_ids'] ?? [],
            'after_live'    => $inspect['after_live_filter'] ?? [],
            'run_select'    => $inspect['wa_recover_run_lead_ids'] ?? [],
            'turn_720'      => $inspect['turn_720'] ?? null,
            'stage'         => 'worker_dry',
        ]);
    }
    if ($asyncAck) {
        exit;
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => true,
        'dry_run' => $inspect,
        'time'    => date('c'),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    turn_schema_lite_ensure();

    $requestLeadIds = $leadIds;
    $explicitLeads = $leadIds !== [];
    if ($leadIds === []) {
        $leadIds = wa_recover_leads_needing_reply($botId, 30);
        wa_recover_trace('needing_reply', [
            'bot_id'        => $botId,
            'request_leads' => $requestLeadIds,
            'leads'         => $leadIds,
        ]);
    }

    if (!$explicitLeads) {
        $kept = [];
        $skippedLive = [];
        foreach ($leadIds as $id) {
            $id = (int) $id;
            if (wa_recover_lead_is_live($id, 20)) {
                $skippedLive[] = $id;
                wa_recover_trace('skipped_live_webhook', ['lead_id' => $id]);
            } else {
                $kept[] = $id;
            }
        }
        $leadIds = array_values($kept);
        wa_recover_trace('after_live_filter', [
            'leads'        => $leadIds,
            'skipped_live' => $skippedLive,
        ]);
    } else {
        error_log('iqp_debounce: worker_explicit leads=' . implode(',', $leadIds));
        if (function_exists('whatsapp_webhook_log_event')) {
            whatsapp_webhook_log_event('Worker 7s quiet wait', [
                'leads' => $leadIds,
                'stage' => 'worker_wait',
            ]);
        }
        wa_recover_wait_leads_quiet($leadIds, 50);
    }

    foreach ($leadIds as $leadId) {
        $holdRow = db_fetch(
            'SELECT id FROM conversation_turns
             WHERE lead_id = ? AND NOT EXISTS (
                SELECT 1 FROM conversation_turn_events e
                WHERE e.turn_id = conversation_turns.id AND e.event_type = \'RESPONSE_SENT\'
             )
             ORDER BY id DESC LIMIT 1',
            'i',
            [(int) $leadId]
        );
        if (!$explicitLeads && $holdRow && wa_recover_is_diagnostic_hold((int) ($holdRow['id'] ?? 0))) {
            continue;
        }
        wa_recover_repair_lead_turn((int) $leadId);
    }

    $quietSec = wa_recover_quiet_seconds();
    $holdIds = wa_recover_diagnostic_hold_turn_ids();
    $holdSql = $holdIds !== []
        ? ' AND id NOT IN (' . implode(',', array_map('intval', $holdIds)) . ')'
        : '';
    db_execute(
        'UPDATE conversation_turns SET finalize_after = NOW()
         WHERE status = \'buffering\'
         AND last_message_at <= DATE_SUB(NOW(), INTERVAL ? SECOND)' . $holdSql,
        'i',
        [$quietSec]
    );

    require_once dirname(__DIR__) . '/includes/conversation-turn-engine.php';
    $sent = 0;
    $results = [];
    foreach ($leadIds as $leadId) {
        $leadId = (int) $leadId;
        $openTurn = db_fetch(
            'SELECT t.id, t.bot_id, t.status FROM conversation_turns t
             WHERE t.lead_id = ?
             AND EXISTS (SELECT 1 FROM conversation_turn_messages ctm WHERE ctm.turn_id = t.id)
             AND NOT EXISTS (
                SELECT 1 FROM conversation_turn_events e
                WHERE e.turn_id = t.id AND e.event_type = \'RESPONSE_SENT\'
             )
             ORDER BY t.id DESC LIMIT 1',
            'i',
            [$leadId]
        );
        $turnId = (int) ($openTurn['id'] ?? 0);
        if (!$explicitLeads && $turnId > 0 && wa_recover_is_diagnostic_hold($turnId)) {
            wa_recover_trace('skipped_diagnostic_hold', [
                'turn_id' => $turnId,
                'lead_id' => $leadId,
            ]);
            $results[] = ['ok' => true, 'lead_id' => $leadId, 'turn_id' => $turnId, 'path' => 'diagnostic_hold'];
            continue;
        }

        $lead = db_fetch('SELECT bot_id FROM leads WHERE id = ?', 'i', [$leadId]);
        $botIdRow = (int) ($lead['bot_id'] ?? ($openTurn['bot_id'] ?? 0));
        $bot = $botIdRow > 0
            ? db_fetch(
                'SELECT b.*, u.company_name FROM bots b JOIN users u ON u.id = b.user_id WHERE b.id = ?',
                'i',
                [$botIdRow]
            )
            : null;
        if (!$bot) {
            wa_recover_trace('skipped_bot_missing', [
                'turn_id' => $turnId,
                'lead_id' => $leadId,
                'bot_id'  => $botIdRow,
            ]);
            $results[] = ['ok' => false, 'lead_id' => $leadId, 'turn_id' => $turnId, 'error' => 'bot missing'];
            continue;
        }
        $phoneId = trim((string) ($bot['whatsapp_phone_id'] ?? ''));
        $token = wa_recover_token_plain((string) ($bot['whatsapp_token'] ?? ''));
        wa_recover_trace('whatsapp_config', [
            'turn_id'      => $turnId,
            'lead_id'      => $leadId,
            'bot_id'       => $botIdRow,
            'has_phone_id' => $phoneId !== '',
            'phone_id'     => $phoneId,
            'token_ok'     => is_string($token) && $token !== '',
        ]);
        if ($phoneId === '') {
            wa_recover_trace('skipped_no_whatsapp_phone_id', [
                'turn_id' => $turnId,
                'lead_id' => $leadId,
                'bot_id'  => $botIdRow,
            ]);
            $results[] = [
                'ok'      => true,
                'lead_id' => $leadId,
                'turn_id' => $turnId,
                'path'    => 'no_whatsapp_phone_id',
            ];
            continue;
        }
        if (!is_string($token) || $token === '') {
            wa_recover_trace('skipped_token_unreadable', [
                'turn_id' => $turnId,
                'lead_id' => $leadId,
                'bot_id'  => $botIdRow,
            ]);
            $results[] = ['ok' => false, 'lead_id' => $leadId, 'turn_id' => $turnId, 'error' => 'token_unreadable'];
            continue;
        }

        $one = turn_engine_send_leads_now([$leadId], $bot, $phoneId, $token);
        $results[] = $one;
        $sent += (int) ($one['sent'] ?? 0);
        wa_recover_trace('pipeline_send_leads_now', [
            'turn_id' => $turnId,
            'lead_id' => $leadId,
            'sent'    => (int) ($one['sent'] ?? 0),
            'path'    => is_array($one['results'][0] ?? null)
                ? (string) (($one['results'][0]['path'] ?? ''))
                : '',
        ]);
    }
    $result = ['ok' => true, 'sent' => $sent, 'hung' => 0, 'results' => $results];
    error_log('iqp_debounce: worker_pipeline_done sent=' . $sent);
    if (function_exists('whatsapp_webhook_log_event')) {
        whatsapp_webhook_log_event('Worker compose result', [
            'request_leads' => $requestLeadIds,
            'leads'         => $leadIds,
            'sent'          => $sent,
            'explicit'      => $explicitLeads,
            'stage'         => 'worker_done',
        ]);
    }

    if ($asyncAck) {
        exit;
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success'     => true,
        'action'      => 'lite_recover',
        'lite'        => true,
        'leads'       => $leadIds,
        'sent'        => (int) ($result['sent'] ?? 0),
        'hung'        => (int) ($result['hung'] ?? 0),
        'results'     => $result['results'] ?? [],
        'turns_after' => turn_worker_turn_counts(),
        'time'        => date('c'),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('turn-worker-lite: ' . $e->getMessage());
    if ($asyncAck) {
        exit;
    }
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'lite'    => true,
        'error'   => $e->getMessage(),
        'turns'   => turn_worker_turn_counts(),
        'time'    => date('c'),
    ], JSON_UNESCAPED_UNICODE);
}
