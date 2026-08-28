<?php
/**
 * IQ Pigeon — A–Z WhatsApp pipeline audit (cPanel-safe).
 *
 * MUST stay light: the previous version 503'd because it loaded qualify+cart+OpenAI
 * and dumped a huge HTML page through the output sanitizer.
 *
 * Default (under 3s): files + bots + open turns + verdict
 *   /api/wa-az-audit.php?key=CRON_SECRET
 *
 * Extra parts (one at a time):
 *   &part=qualify   industry + per-bot qualification
 *   &part=orders    catalog counts + recent orders
 *   &part=logs      webhook + error tails
 *   &part=messages  SELECT-only conversation_turn_messages for &turn_id=
 *   &part=compose   dry compose one open turn (no Graph send)
 *   &fix=1          send waiting replies (fallback, no OpenAI)
 */
declare(strict_types=1);

@set_time_limit(20);
ignore_user_abort(true);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';

while (ob_get_level() > 0) {
    @ob_end_clean();
}
header('Content-Type: application/json; charset=utf-8');

$cron = defined('CRON_SECRET') ? (string) CRON_SECRET : '';
$key = trim((string) ($_GET['key'] ?? $_POST['key'] ?? ''));
if ($cron === '' || !hash_equals($cron, $key)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden — use the CRON_SECRET value from config.local.php, not the placeholder YOUR_CRON_SECRET']);
    exit;
}

$root = dirname(__DIR__);
$part = preg_replace('/[^a-z]/', '', (string) ($_GET['part'] ?? 'core')) ?: 'core';
$botId = (int) ($_GET['bot_id'] ?? 0);
$turnId = (int) ($_GET['turn_id'] ?? 0);
$doFix = ($_GET['fix'] ?? '') === '1';

function az_has(string $path, string $needle): bool
{
    if (!is_readable($path)) {
        return false;
    }
    $src = (string) file_get_contents($path);

    return $src !== '' && str_contains($src, $needle);
}

function az_mtime(string $path): ?string
{
    return is_file($path) ? date('c', (int) filemtime($path)) : null;
}

/** @return list<string> */
function az_tail(string $path, int $maxLines = 20, int $maxBytes = 40000): array
{
    if (!is_file($path) || !is_readable($path)) {
        return [];
    }
    $size = (int) filesize($path);
    $fp = fopen($path, 'rb');
    if ($fp === false) {
        return [];
    }
    if ($size > $maxBytes) {
        fseek($fp, -$maxBytes, SEEK_END);
        fgets($fp);
    }
    $data = (string) stream_get_contents($fp);
    fclose($fp);
    $lines = preg_split('/\r\n|\n|\r/', trim($data)) ?: [];

    return array_values(array_slice($lines, -$maxLines));
}

$webhook = $root . '/api/whatsapp-webhook.php';
$engine = $root . '/includes/conversation-turn-engine.php';
$recover = $root . '/includes/wa-recover-lite.php';
$core = $root . '/includes/whatsapp-auto-reply-core.php';
$human = $root . '/includes/whatsapp-human-layer.php';

$ackThenAsync = az_has($webhook, 'function wa_webhook_ack_meta')
    && az_has($webhook, 'fastcgi_finish_request')
    && az_has($webhook, 'function wa_webhook_release_output_buffers')
    && az_has($engine, 'function turn_engine_send_leads_now');

$report = [
    'ok'   => true,
    'part' => $part,
    'time' => date('c'),
    'php'  => PHP_VERSION,
    'hint' => 'Default is core only (no 503). Add &part=qualify | orders | logs | messages | compose. Add &fix=1 to send waiting chats.',
];

if ($part === 'core' || $part === 'all') {
    $report['a_files'] = [
        'webhook_mtime'        => az_mtime($webhook),
        'engine_mtime'         => az_mtime($engine),
        'send_before_meta_ack' => $ackThenAsync,
        'ack_then_async'       => $ackThenAsync,
        'dup_requeue_send'     => az_has($webhook, 'DUPLICATE_REQUEUE_SEND'),
        'ingest_no_cart'       => !az_has($engine, 'cart_message_is_shop_interrupt'),
        'already_replied_open_turn' => az_has($recover, 'no unanswered inbound turn'),
        'deliver_skips_only_this_turn' => !az_has($core, 'wa_recover_lead_already_replied($leadId)'),
        'quiet_wait_5s'        => az_has($engine, 'Silent wait until 7s after last inbound'),
        'quiet_wait_7s'        => az_has($engine, 'Silent wait until 7s after last inbound')
            && az_has($engine, 'min(18000'),
        'wait_quiet_no_typing' => az_has($engine, 'Silent wait until 7s after last inbound'),
        'webhook_mind'         => az_has($core, 'function wa_webhook_mind_reply')
            && az_has($engine, "\$GLOBALS['wa_skip_openai'] = true"),
        'webhook_instant'      => az_has($core, 'function wa_webhook_instant_reply'),
        'must_send_armed'      => az_has($engine, 'function turn_engine_arm_must_send'),
        'cart_before_openai'   => az_has($human, 'wa_human_layer_try_cart_reply'),
        'qualify_file'         => is_file($root . '/includes/qualification-flow.php'),
        'cart_file'            => is_file($root . '/includes/cart.php'),
    ];

    $botSql = $botId > 0 ? ' AND id = ?' : '';
    $botParams = $botId > 0 ? [$botId] : [];
    $report['c_bots'] = db_fetch_all(
        'SELECT id, name, is_active, whatsapp_auto_reply, whatsapp_phone_id, industry_key
         FROM bots
         WHERE whatsapp_phone_id IS NOT NULL AND whatsapp_phone_id != \'\'' . $botSql . '
         ORDER BY id DESC LIMIT 12',
        str_repeat('i', count($botParams)),
        $botParams
    ) ?: [];

    $turnBot = $botId > 0 ? ' AND t.bot_id = ?' : '';
    $turnParams = $botId > 0 ? [$botId] : [];
    $report['d_turns'] = [
        'buffering'  => (int) (db_fetch('SELECT COUNT(*) AS c FROM conversation_turns WHERE status = \'buffering\'', '', [])['c'] ?? 0),
        'processing' => (int) (db_fetch('SELECT COUNT(*) AS c FROM conversation_turns WHERE status = \'processing\'', '', [])['c'] ?? 0),
        'needs_reply'=> (int) (db_fetch(
            'SELECT COUNT(DISTINCT t.lead_id) AS c FROM conversation_turns t
             WHERE EXISTS (SELECT 1 FROM conversation_turn_messages ctm WHERE ctm.turn_id = t.id)
             AND NOT EXISTS (
                SELECT 1 FROM conversation_turn_events e
                WHERE e.turn_id = t.id AND e.event_type = \'RESPONSE_SENT\'
             )',
            '',
            []
        )['c'] ?? 0),
        'open' => db_fetch_all(
            'SELECT t.id, t.lead_id, t.bot_id, t.status, t.suppression_reason, t.message_count, t.last_message_at
             FROM conversation_turns t
             WHERE EXISTS (SELECT 1 FROM conversation_turn_messages ctm WHERE ctm.turn_id = t.id)
             AND NOT EXISTS (
                SELECT 1 FROM conversation_turn_events e
                WHERE e.turn_id = t.id AND e.event_type = \'RESPONSE_SENT\'
             )' . $turnBot . '
             ORDER BY t.id DESC LIMIT 8',
            str_repeat('i', count($turnParams)),
            $turnParams
        ) ?: [],
    ];

    $blockers = [];
    if (!$ackThenAsync) {
        $blockers[] = 'Webhook on this server still holds Meta before HTTP 200, or still sanitizes the verify challenge. Re-upload api/whatsapp-webhook.php, includes/security-output.php, and config.php.';
    }
    foreach ($report['c_bots'] as $b) {
        if ((int) ($b['whatsapp_auto_reply'] ?? 1) !== 1) {
            $blockers[] = 'Bot #' . (int) $b['id'] . ' auto-reply is OFF.';
        }
    }
    if ((int) ($report['d_turns']['needs_reply'] ?? 0) > 0) {
        $blockers[] = 'Unanswered turns are waiting. After webhook upload, send a new WhatsApp line, or add &fix=1.';
    }
    $report['verdict'] = $blockers === []
        ? ['status' => 'healthy', 'summary' => 'Webhook ACKs Meta immediately, then composes asynchronously.']
        : ['status' => 'blocked', 'blockers' => $blockers];
}

if ($part === 'qualify') {
    try {
        require_once $root . '/includes/qualification-flow.php';
        $keys = function_exists('industry_template_keys')
            ? industry_template_keys()
            : array_keys(industry_training_templates());
        $industries = [];
        foreach ($keys as $k) {
            $tpl = industry_template((string) $k);
            $industries[] = [
                'key'       => $k,
                'label'     => is_array($tpl) ? (string) ($tpl['label'] ?? $k) : (string) $k,
                'questions' => is_array($tpl) ? count($tpl['questions'] ?? []) : 0,
            ];
        }
        $perBot = [];
        $bots = db_fetch_all(
            'SELECT * FROM bots WHERE whatsapp_phone_id IS NOT NULL AND whatsapp_phone_id != \'\' ORDER BY id DESC LIMIT 8',
            '',
            []
        ) ?: [];
        foreach ($bots as $bot) {
            $qs = qualification_effective_questions($bot);
            $perBot[] = [
                'bot_id'         => (int) $bot['id'],
                'industry_key'   => (string) ($bot['industry_key'] ?? ''),
                'custom'         => qualification_is_custom($bot),
                'question_count' => count($qs),
            ];
        }
        $report['h_qualify'] = [
            'industry_count' => count($industries),
            'industries'     => $industries,
            'bots'           => $perBot,
        ];
    } catch (Throwable $e) {
        $report['h_qualify'] = ['error' => $e->getMessage()];
    }
}

if ($part === 'orders') {
    try {
        $report['i_orders'] = [
            'cart_handle_command' => az_has($root . '/includes/cart.php', 'function cart_handle_command'),
            'cart_try_place_order'=> az_has($root . '/includes/cart.php', 'function cart_try_place_order'),
            'catalog' => db_fetch_all(
                'SELECT bot_id, COUNT(*) AS items FROM bot_products WHERE is_active = 1 GROUP BY bot_id LIMIT 12',
                '',
                []
            ) ?: [],
            'recent_orders' => db_fetch_all(
                'SELECT id, bot_id, lead_id, status, total, created_at FROM bot_orders ORDER BY id DESC LIMIT 8',
                '',
                []
            ) ?: [],
        ];
    } catch (Throwable $e) {
        $report['i_orders'] = ['error' => $e->getMessage()];
    }
}

if ($part === 'logs') {
    $report['e_webhook_log'] = az_tail($root . '/storage/whatsapp-webhook.log', 25);
    $err = [];
    foreach ([$root . '/api/error_log', $root . '/error_log'] as $p) {
        $err = array_merge($err, az_tail($p, 15));
    }
    $report['f_error_log'] = array_slice($err, -20);
}

if ($part === 'messages') {
    try {
        if ($turnId <= 0) {
            $report['m_messages'] = [
                'ok'          => false,
                'select_only' => true,
                'sends'       => false,
                'error'       => 'turn_id is required',
            ];
        } else {
            $rows = db_fetch_all(
                'SELECT id, turn_id, message_type, raw_text, caption, processing_status, sort_order
                 FROM conversation_turn_messages
                 WHERE turn_id = ?
                 ORDER BY sort_order ASC, id ASC
                 LIMIT 50',
                'i',
                [$turnId]
            ) ?: [];
            $safe = [];
            foreach ($rows as $row) {
                $safe[] = [
                    'id'                 => (int) ($row['id'] ?? 0),
                    'turn_id'            => (int) ($row['turn_id'] ?? 0),
                    'message_type'       => (string) ($row['message_type'] ?? ''),
                    'raw_text'           => (string) ($row['raw_text'] ?? ''),
                    'caption'            => (string) ($row['caption'] ?? ''),
                    'processing_status'  => (string) ($row['processing_status'] ?? ''),
                    'sort_order'         => (int) ($row['sort_order'] ?? 0),
                ];
            }
            $report['m_messages'] = [
                'ok'          => true,
                'select_only' => true,
                'sends'       => false,
                'turn_id'     => $turnId,
                'count'       => count($safe),
                'messages'    => $safe,
            ];
        }
    } catch (Throwable $e) {
        $report['m_messages'] = [
            'ok'          => false,
            'select_only' => true,
            'sends'       => false,
            'error'       => $e->getMessage(),
        ];
    }
}

if ($part === 'compose') {
    try {
        require_once $root . '/includes/whatsapp-auto-reply-core.php';
        $open = db_fetch(
            'SELECT t.* FROM conversation_turns t
             WHERE EXISTS (SELECT 1 FROM conversation_turn_messages ctm WHERE ctm.turn_id = t.id)
             AND NOT EXISTS (
                SELECT 1 FROM conversation_turn_events e
                WHERE e.turn_id = t.id AND e.event_type = \'RESPONSE_SENT\'
             )
             ORDER BY t.id DESC LIMIT 1',
            '',
            []
        );
        if (!$open) {
            $report['j_compose'] = ['info' => 'No unanswered turn'];
        } else {
            $tBot = db_fetch(
                'SELECT b.*, u.company_name FROM bots b JOIN users u ON u.id = b.user_id WHERE b.id = ?',
                'i',
                [(int) $open['bot_id']]
            );
            $text = wa_auto_reply_turn_text((int) $open['id'], (int) $open['lead_id']);
            $composed = $tBot
                ? wa_auto_reply_compose($tBot, (int) $open['lead_id'], $text, (int) $open['id'], false)
                : ['reply' => '', 'path' => 'no_bot'];
            $report['j_compose'] = [
                'turn_id'   => (int) $open['id'],
                'lead_id'   => (int) $open['lead_id'],
                'user_text' => mb_substr($text, 0, 200),
                'path'      => $composed['path'] ?? '',
                'reply'     => mb_substr((string) ($composed['reply'] ?? ''), 0, 200),
                'note'      => 'core compose only (no OpenAI) to avoid 503',
            ];
        }
    } catch (Throwable $e) {
        $report['j_compose'] = ['error' => $e->getMessage()];
    }
}

if ($doFix) {
    require_once $root . '/includes/wa-recover-lite.php';
    $t0 = microtime(true);
    $report['k_fix'] = wa_recover_run($botId, false, 3);
    $report['k_fix']['ms'] = (int) round((microtime(true) - $t0) * 1000);
}

echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
