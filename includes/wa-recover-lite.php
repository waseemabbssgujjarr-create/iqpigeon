<?php
/**
 * Minimal WhatsApp turn recovery — no conversation-turn-engine.php, no whatsapp.php, no helpers.php.
 * Used by api/wa-recover.php, api/wa-fallback.php, scripts/wa-recover.php
 */
declare(strict_types=1);

function wa_recover_auth(string $key): bool
{
    $cron = defined('CRON_SECRET') ? (string) CRON_SECRET : '';

    return $cron !== '' && hash_equals($cron, $key);
}

/**
 * Existing unanswered diagnostic turns — recover must not Graph-send these
 * until the operator explicitly confirms (turn 720 / bot 57 first).
 *
 * @return list<int>
 */
function wa_recover_diagnostic_hold_turn_ids(): array
{
    return [720, 635, 321, 306, 302, 134];
}

function wa_recover_is_diagnostic_hold(int $turnId): bool
{
    return $turnId > 0 && in_array($turnId, wa_recover_diagnostic_hold_turn_ids(), true);
}

/**
 * Append-only recover trace. Never log tokens or full message bodies.
 *
 * @param array<string, mixed> $detail
 */
function wa_recover_trace(string $stage, array $detail = []): void
{
    $detail['stage'] = $stage;
    if (function_exists('whatsapp_webhook_log_event')) {
        whatsapp_webhook_log_event('Recover ' . $stage, $detail);
    } else {
        error_log('iqp_recover: ' . $stage . ' ' . json_encode($detail, JSON_UNESCAPED_UNICODE));
    }
}

function wa_recover_mask_phone(string $phone): string
{
    $digits = preg_replace('/\D/', '', $phone) ?? '';
    if ($digits === '') {
        return '';
    }
    if (strlen($digits) <= 4) {
        return '****';
    }

    return str_repeat('*', max(0, strlen($digits) - 4)) . substr($digits, -4);
}

/**
 * SELECT-only worker simulation. No UPDATEs, no Graph send.
 *
 * @return array<string, mixed>
 */
function wa_recover_dry_inspect(int $botId = 0, int $limit = 30): array
{
    require_once __DIR__ . '/turn-schema-lite.php';
    turn_schema_lite_ensure();

    try {

    $needed = wa_recover_leads_needing_reply($botId, $limit);
    $afterLive = [];
    $skippedLive = [];
    foreach ($needed as $leadId) {
        $leadId = (int) $leadId;
        if (wa_recover_lead_is_live($leadId, 20)) {
            $skippedLive[] = $leadId;
        } else {
            $afterLive[] = $leadId;
        }
    }

    $botSql = $botId > 0 ? ' AND t.bot_id = ?' : '';
    $params = $botId > 0 ? [$botId] : [];
    $runRows = db_fetch_all(
        'SELECT t.lead_id, MAX(t.bot_id) AS bot_id, MAX(t.id) AS latest_turn_id
         FROM conversation_turns t
         WHERE t.lead_id > 0
         AND (
            t.status IN (\'failed\', \'buffering\', \'cancelled\')
            OR (
                t.status = \'processing\'
                AND t.processing_started_at IS NOT NULL
                AND t.processing_started_at < DATE_SUB(NOW(), INTERVAL 2 SECOND)
            )
         )
         AND NOT EXISTS (
            SELECT 1 FROM conversation_turn_events e
            WHERE e.turn_id = t.id AND e.event_type = \'RESPONSE_SENT\'
         )' . $botSql . '
         GROUP BY t.lead_id
         ORDER BY latest_turn_id DESC
         LIMIT ' . max(1, min(30, $limit)),
        str_repeat('i', count($params)),
        $params
    ) ?: [];

    $dueBuffering = db_fetch_all(
        'SELECT id, lead_id, bot_id, status, finalize_after, last_message_at, processing_started_at
         FROM conversation_turns
         WHERE status = \'buffering\' AND finalize_after <= NOW()'
        . ($botId > 0 ? ' AND bot_id = ?' : ''),
        $botId > 0 ? 'i' : '',
        $botId > 0 ? [$botId] : []
    ) ?: [];

    $turns = [];
    $turn720 = null;
    $open = db_fetch_all(
        'SELECT t.id, t.lead_id, t.bot_id, t.status, t.suppression_reason, t.message_count,
                t.last_message_at, t.finalize_after, t.processing_started_at, t.sender_phone,
                b.name AS bot_name, b.is_active, b.whatsapp_auto_reply, b.whatsapp_phone_id,
                (b.whatsapp_phone_id IS NOT NULL AND b.whatsapp_phone_id != \'\') AS has_phone_id,
                (LENGTH(IFNULL(b.whatsapp_token, \'\')) > 0) AS has_token_stored,
                l.id AS lead_row_id, l.status AS lead_status, l.external_id
         FROM conversation_turns t
         LEFT JOIN bots b ON b.id = t.bot_id
         LEFT JOIN leads l ON l.id = t.lead_id
         WHERE EXISTS (SELECT 1 FROM conversation_turn_messages ctm WHERE ctm.turn_id = t.id)
         AND NOT EXISTS (
            SELECT 1 FROM conversation_turn_events e
            WHERE e.turn_id = t.id AND e.event_type = \'RESPONSE_SENT\'
         )
         ORDER BY t.id DESC
         LIMIT 12',
        '',
        []
    ) ?: [];

    foreach ($open as $row) {
        $turnId = (int) ($row['id'] ?? 0);
        $leadId = (int) ($row['lead_id'] ?? 0);
        $rowBotId = (int) ($row['bot_id'] ?? 0);
        $sender = trim((string) ($row['sender_phone'] ?? ''));
        if ($sender === '') {
            // Same identity the ingest path stores: leads.external_id (WhatsApp sender).
            // Do not query leads.phone — that column does not exist in production.
            $sender = trim((string) ($row['external_id'] ?? ''));
        }
        $tokenStored = (int) ($row['has_token_stored'] ?? 0) === 1;
        $tokenOk = false;
        if ($tokenStored && $rowBotId > 0) {
            $tokRow = db_fetch('SELECT whatsapp_token FROM bots WHERE id = ?', 'i', [$rowBotId]);
            $plain = wa_recover_token_plain((string) ($tokRow['whatsapp_token'] ?? ''));
            $tokenOk = is_string($plain) && $plain !== '';
        }
        $hasPhoneId = (int) ($row['has_phone_id'] ?? 0) === 1;
        $live = wa_recover_lead_is_live($leadId, 20);
        $due = (string) ($row['status'] ?? '') === 'buffering'
            && strtotime((string) ($row['finalize_after'] ?? '')) <= time();
        $hold = wa_recover_is_diagnostic_hold($turnId);
        $reasons = [];
        if ($live) {
            $reasons[] = 'live_webhook_20s';
        }
        if (!$hasPhoneId) {
            $reasons[] = 'no_whatsapp_phone_id';
        }
        if (!$tokenOk) {
            $reasons[] = $tokenStored ? 'token_unreadable' : 'token_empty';
        }
        if ($sender === '') {
            $reasons[] = 'no_sender_phone';
        }
        if ((int) ($row['whatsapp_auto_reply'] ?? 1) !== 1) {
            $reasons[] = 'auto_reply_off';
        }
        if ((int) ($row['lead_row_id'] ?? 0) <= 0) {
            $reasons[] = 'lead_missing';
        }
        $inNeeded = in_array($leadId, $needed, true);
        $inAfterLive = in_array($leadId, $afterLive, true);
        $inRunSelect = false;
        foreach ($runRows as $rr) {
            if ((int) ($rr['lead_id'] ?? 0) === $leadId) {
                $inRunSelect = true;
                break;
            }
        }
        if (!$inNeeded) {
            $reasons[] = 'not_in_needing_reply_query';
        }
        if ($inNeeded && !$inAfterLive) {
            $reasons[] = 'dropped_by_live_filter';
        }
        if (!$inRunSelect) {
            $reasons[] = 'not_in_wa_recover_run_select';
        }
        $hiddenByFinalizeRace = $due && (string) ($row['status'] ?? '') === 'buffering';

        $eligible = $reasons === [] && $inNeeded && $inAfterLive;
        $entry = [
            'turn_id'                    => $turnId,
            'lead_id'                    => $leadId,
            'bot_id'                     => $rowBotId,
            'bot_name'                   => (string) ($row['bot_name'] ?? ''),
            'status'                     => (string) ($row['status'] ?? ''),
            'finalize_after'             => (string) ($row['finalize_after'] ?? ''),
            'last_message_at'            => (string) ($row['last_message_at'] ?? ''),
            'due'                        => $due,
            'selected'                   => $inNeeded && $inAfterLive,
            'eligible'                   => $eligible,
            'skip_reason'                => $reasons === [] ? 'none' : $reasons[0],
            'in_needing_reply'           => $inNeeded,
            'in_after_live_filter'       => $inAfterLive,
            'in_wa_recover_run_select'   => $inRunSelect,
            'would_hide_if_finalize_then_2s_select' => $hiddenByFinalizeRace,
            'has_phone_id'               => $hasPhoneId,
            'phone_id'                   => $hasPhoneId ? (string) ($row['whatsapp_phone_id'] ?? '') : '',
            'whatsapp_phone_id'          => $hasPhoneId ? (string) ($row['whatsapp_phone_id'] ?? '') : '',
            'token_ok'                   => $tokenOk,
            'sender_present'             => $sender !== '',
            'sender_masked'              => wa_recover_mask_phone($sender),
            'lead_exists'                => (int) ($row['lead_row_id'] ?? 0) > 0,
            'lead_status'                => (string) ($row['lead_status'] ?? ''),
            'auto_reply'                 => (int) ($row['whatsapp_auto_reply'] ?? 1),
            'is_active'                  => (int) ($row['is_active'] ?? 0),
            'diagnostic_hold'            => $hold,
            'would_graph_send'           => $eligible && !$hold,
            'skip_reasons'               => $reasons,
        ];
        $turns[] = $entry;
        if ($turnId === 720) {
            $turn720 = $entry;
        }
    }

    return [
        'mode'                    => 'dry_run_select_only',
        'sends'                   => false,
        'mutates_turns'           => false,
        'needing_reply_lead_ids'  => $needed,
        'skipped_live_lead_ids'   => $skippedLive,
        'after_live_filter'       => $afterLive,
        'wa_recover_run_lead_ids' => array_map(static fn ($r) => (int) ($r['lead_id'] ?? 0), $runRows),
        'due_buffering_turn_ids'  => array_map(static fn ($r) => (int) ($r['id'] ?? 0), $dueBuffering),
        'diagnostic_hold_ids'     => wa_recover_diagnostic_hold_turn_ids(),
        'turns'                   => $turns,
        'turn_720'                => $turn720,
    ];
    } catch (Throwable $e) {
        return [
            'mode'   => 'dry_run_select_only',
            'sends'  => false,
            'error'  => $e->getMessage(),
            'turns'  => [],
            'turn_720' => null,
        ];
    }
}

/** @return string|false */
function wa_recover_decrypt_token(string $ciphertext)
{
    $keys = [];
    foreach (['ENCRYPTION_KEY', 'ENCRYPT_KEY'] as $name) {
        if (defined($name) && trim((string) constant($name)) !== '') {
            $keys[] = trim((string) constant($name));
        }
    }
    $keys = array_values(array_unique($keys));
    if ($keys === []) {
        return false;
    }

    $data = base64_decode($ciphertext, true);
    if ($data === false) {
        return false;
    }

    foreach ($keys as $material) {
        $key = hash('sha256', $material, true);
        if (strlen($data) >= 17) {
            $iv = substr($data, 0, 16);
            $encrypted = substr($data, 16);
            $plain = openssl_decrypt($encrypted, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
            if ($plain !== false) {
                return $plain;
            }
        }
    }

    return false;
}

/** @return string|false */
function wa_recover_token_plain(string $stored)
{
    $stored = trim($stored);
    if ($stored === '') {
        return false;
    }
    $plain = wa_recover_decrypt_token($stored);
    if (is_string($plain) && $plain !== '') {
        return $plain;
    }
    if (preg_match('/^EAA[A-Za-z0-9]+$/', $stored) === 1) {
        return $stored;
    }

    return false;
}

function wa_recover_graph_version(): string
{
    $raw = defined('META_GRAPH_API_VERSION') ? trim((string) META_GRAPH_API_VERSION) : 'v25.0';
    if (preg_match('/^v\d+\.\d+$/', $raw)) {
        return $raw;
    }
    if (preg_match('/^\d+\.\d+$/', $raw)) {
        return 'v' . $raw;
    }

    return 'v25.0';
}

/**
 * @return array{success: bool, message?: string, http_code?: int}
 */
function wa_recover_send_whatsapp(string $phoneId, string $token, string $to, string $text): array
{
    $url = 'https://graph.facebook.com/' . wa_recover_graph_version() . '/' . $phoneId . '/messages';
    $payload = json_encode([
        'messaging_product' => 'whatsapp',
        'to'                => preg_replace('/\D/', '', $to),
        'type'              => 'text',
        'text'              => ['body' => $text],
    ], JSON_UNESCAPED_UNICODE);

    wa_recover_trace('graph_start', [
        'phone_id' => $phoneId,
        'to'       => wa_recover_mask_phone($to),
        'chars'    => strlen($text),
    ]);

    $ch = curl_init($url);
    $opts = [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
        ],
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_CONNECTTIMEOUT => 12,
        CURLOPT_TIMEOUT        => 20,
    ];
    if (defined('CURL_IPRESOLVE_V4')) {
        $opts[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4;
    }
    if (defined('META_GRAPH_SSL_VERIFY') && !META_GRAPH_SSL_VERIFY) {
        $opts[CURLOPT_SSL_VERIFYPEER] = false;
        $opts[CURLOPT_SSL_VERIFYHOST] = 0;
    }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err !== '') {
        wa_recover_trace('graph_end', [
            'ok'        => false,
            'http_code' => 0,
            'error'     => mb_substr($err, 0, 180),
        ]);

        return ['success' => false, 'message' => $err, 'http_code' => 0];
    }
    if ($http >= 400) {
        $decoded = is_string($body) ? json_decode($body, true) : null;
        $msg = is_array($decoded) ? (string) ($decoded['error']['message'] ?? 'send failed') : 'send failed';
        wa_recover_trace('graph_end', [
            'ok'        => false,
            'http_code' => $http,
            'error'     => mb_substr($msg, 0, 180),
        ]);

        return ['success' => false, 'message' => $msg, 'http_code' => $http];
    }

    wa_recover_trace('graph_end', [
        'ok'        => true,
        'http_code' => $http,
    ]);

    return ['success' => true, 'http_code' => $http];
}

function wa_recover_turn_text(int $turnId): string
{
    $rows = db_fetch_all(
        'SELECT raw_text FROM conversation_turn_messages
         WHERE turn_id = ? AND message_type = \'text\'
         ORDER BY sort_order ASC, id ASC',
        'i',
        [$turnId]
    ) ?: [];
    $parts = [];
    foreach ($rows as $row) {
        $t = trim((string) ($row['raw_text'] ?? ''));
        if ($t !== '') {
            $parts[] = $t;
        }
    }

    return trim(implode("\n", array_slice($parts, -5)));
}

/**
 * Merge open turns + build full customer text for recover / deliver.
 */
function wa_recover_lead_user_text(int $leadId, int $turnId): string
{
    if ($leadId > 0) {
        $engine = __DIR__ . '/conversation-turn-engine.php';
        if (is_readable($engine)) {
            require_once $engine;
            if (function_exists('turn_engine_consolidate_open_turns_for_lead')) {
                turn_engine_consolidate_open_turns_for_lead($leadId);
            }
            if ($turnId <= 0) {
                $open = db_fetch(
                    'SELECT id FROM conversation_turns
                     WHERE lead_id = ? AND NOT EXISTS (
                        SELECT 1 FROM conversation_turn_events e
                        WHERE e.turn_id = conversation_turns.id AND e.event_type = \'RESPONSE_SENT\'
                     )
                     ORDER BY id DESC LIMIT 1',
                    'i',
                    [$leadId]
                );
                $turnId = (int) ($open['id'] ?? 0);
            }
        }
    }

    $text = '';
    if ($turnId > 0 && function_exists('turn_engine_build_turn_payload')) {
        try {
            $payload = turn_engine_build_turn_payload($turnId);
            $text = trim((string) ($payload['combined'] ?? ''));
        } catch (Throwable $e) {
            error_log('wa_recover_lead_user_text payload #' . $turnId . ': ' . $e->getMessage());
        }
    }
    if ($text === '' && $turnId > 0) {
        $text = wa_recover_turn_text($turnId);
    }

    if ($leadId > 0) {
        $lastRow = db_fetch(
            'SELECT message FROM conversations WHERE lead_id = ? AND role = \'user\' ORDER BY id DESC LIMIT 1',
            'i',
            [$leadId]
        );
        $lastUser = trim((string) ($lastRow['message'] ?? ''));
        if ($lastUser !== '' && ($text === '' || !str_contains($text, $lastUser))) {
            $text = $text === '' ? $lastUser : trim($text . "\n" . $lastUser);
        }
    }

    return trim($text);
}

function wa_recover_response_sent(int $turnId): bool
{
    $row = db_fetch(
        'SELECT id FROM conversation_turn_events WHERE turn_id = ? AND event_type = \'RESPONSE_SENT\' LIMIT 1',
        'i',
        [$turnId]
    );

    return $row !== null;
}

function wa_recover_lead_already_replied(int $leadId): bool
{
    if ($leadId <= 0) {
        return false;
    }

    // Only skip when this lead has no unanswered inbound turn.
    // A prior RESPONSE_SENT on an older turn must NOT block later messages.
    $open = db_fetch(
        'SELECT t.id FROM conversation_turns t
         WHERE t.lead_id = ?
         AND EXISTS (SELECT 1 FROM conversation_turn_messages ctm WHERE ctm.turn_id = t.id)
         AND NOT EXISTS (
            SELECT 1 FROM conversation_turn_events e
            WHERE e.turn_id = t.id AND e.event_type = \'RESPONSE_SENT\'
         )
         LIMIT 1',
        'i',
        [$leadId]
    );

    return $open === null;
}

/**
 * After one successful reply for a lead, close sibling turns so recover/worker never double-sends.
 */
function wa_recover_close_lead_turns(int $leadId, int $primaryTurnId, string $path, string $reply = ''): void
{
    if ($leadId <= 0 || $primaryTurnId <= 0) {
        return;
    }

    $primary = db_fetch(
        'SELECT last_message_at FROM conversation_turns WHERE id = ?',
        'i',
        [$primaryTurnId]
    );
    $primaryAt = (string) ($primary['last_message_at'] ?? '');

    $siblings = db_fetch_all(
        'SELECT id FROM conversation_turns
         WHERE lead_id = ? AND id != ?
         AND (last_message_at IS NULL OR last_message_at <= ?)
         AND NOT EXISTS (
            SELECT 1 FROM conversation_turn_events e
            WHERE e.turn_id = conversation_turns.id AND e.event_type = \'RESPONSE_SENT\'
         )',
        'iis',
        [$leadId, $primaryTurnId, $primaryAt !== '' ? $primaryAt : '1970-01-01 00:00:00']
    ) ?: [];

    foreach ($siblings as $row) {
        $sid = (int) ($row['id'] ?? 0);
        if ($sid <= 0) {
            continue;
        }
        db_execute(
            'UPDATE conversation_turns SET status = \'completed\', ai_response_text = ?,
             processing_completed_at = NOW(), suppression_reason = ? WHERE id = ?',
            'ssi',
            [$reply, 'merged_reply:' . $path, $sid]
        );
        wa_recover_log_event($sid, 'RESPONSE_SENT', [
            'path'        => $path,
            'merged_into' => $primaryTurnId,
            'layer'       => 'wa_recover_sibling_close',
        ]);
    }
}

function wa_recover_log_event(int $turnId, string $type, array $detail = []): void
{
    try {
        $json = $detail !== [] ? json_encode($detail, JSON_UNESCAPED_UNICODE) : null;
        db_execute(
            'INSERT INTO conversation_turn_events (turn_id, event_type, detail_json) VALUES (?, ?, ?)',
            'iss',
            [$turnId, $type, $json]
        );
    } catch (Throwable $e) {
        error_log('wa_recover_log_event: ' . $e->getMessage());
    }
}

function wa_recover_persist_chat(int $leadId, string $userText, string $reply): void
{
    try {
        if ($userText !== '') {
            db_execute(
                'INSERT INTO conversations (lead_id, role, message) VALUES (?, \'user\', ?)',
                'is',
                [$leadId, $userText]
            );
        }
        db_execute(
            'INSERT INTO conversations (lead_id, role, message) VALUES (?, \'assistant\', ?)',
            'is',
            [$leadId, $reply]
        );
    } catch (Throwable $e) {
        error_log('wa_recover_persist_chat: ' . $e->getMessage());
    }
}

function wa_recover_simple_fallback(array $bot, string $userMessage): string
{
    $brand = trim((string) ($bot['company_name'] ?? (defined('APP_NAME') ? APP_NAME : 'us')));
    $msg = mb_strtolower(trim($userMessage));

    if ($msg === '' || preg_match('/^(hi+|hello+|hey+)\b/u', $msg)) {
        return "Hi! Thanks for messaging {$brand} — what can I get for you?";
    }
    if (preg_match('/\b(menu|order|offer|price|what you have|what are you|where are you)\b/u', $msg)) {
        return "Happy to help — tell me what you're looking for and I'll guide you at {$brand}.";
    }

    return "Got it — I'm with {$brand}. What would you like to know?";
}

function wa_recover_fallback_text(array $bot, string $userMessage, int $leadId): string
{
    try {
        require_once __DIR__ . '/human-agent-prompt.php';
        if (function_exists('human_agent_warm_last_resort')) {
            $warm = human_agent_warm_last_resort($bot, $userMessage !== '' ? $userMessage : 'Hi', $leadId);
            if (trim($warm) !== '') {
                return $warm;
            }
        }
    } catch (Throwable $ignored) {
    }

    return wa_recover_simple_fallback($bot, $userMessage);
}

function wa_recover_openai_reply(int $leadId, array $bot, string $userMessage): ?string
{
    if (trim($userMessage) === '') {
        return null;
    }

    require_once __DIR__ . '/integration-settings.php';
    require_once __DIR__ . '/openai.php';

    if (integration_openai_chat_key() === '') {
        return null;
    }

    $company = trim((string) ($bot['company_name'] ?? (defined('APP_NAME') ? APP_NAME : 'Business')));
    $brand = trim((string) ($bot['name'] ?? $company));
    if ($brand === '') {
        $brand = $company;
    }

    $system = "You are a real person replying on WhatsApp for {$brand} ({$company}). "
        . 'Answer what they asked in 1–4 warm sentences. Never say "how can I help you today" or ask them to repeat.';

    $history = db_fetch_all(
        'SELECT role, message FROM conversations WHERE lead_id = ? ORDER BY id DESC LIMIT 6',
        'i',
        [$leadId]
    ) ?: [];
    $history = array_reverse($history);

    $messages = [['role' => 'system', 'content' => $system]];
    foreach ($history as $row) {
        $role = (string) ($row['role'] ?? '');
        if ($role === 'system') {
            continue;
        }
        $messages[] = [
            'role'    => $role === 'assistant' ? 'assistant' : 'user',
            'content' => mb_substr((string) ($row['message'] ?? ''), 0, 400),
        ];
    }
    $messages[] = ['role' => 'user', 'content' => mb_substr($userMessage, 0, 800)];

    $result = ai_chat($messages, [
        'timeout'      => 10,
        'max_attempts' => 1,
        'max_tokens'   => 220,
        'temperature'  => 0.35,
    ]);

    if (!empty($result['success']) && trim((string) ($result['content'] ?? '')) !== '') {
        return trim((string) $result['content']);
    }

    return null;
}

/**
 * Compose reply for recover API — lightweight (no human-agent / catalog chain).
 *
 * @param array<string, mixed> $bot
 * @return array{reply: string, path: string}
 */
function wa_recover_compose_reply(int $leadId, array $bot, string $userMessage, bool $useAi = true): array
{
    $brand = trim((string) ($bot['company_name'] ?? (defined('APP_NAME') ? APP_NAME : 'our team')));
    $msg = mb_strtolower(trim($userMessage));

    if (!$useAi) {
        return ['reply' => wa_recover_simple_fallback($bot, $userMessage), 'path' => 'recover_quick'];
    }

    require_once __DIR__ . '/whatsapp-human-layer.php';
    $cartReply = wa_human_layer_try_cart_reply($bot, $leadId, $userMessage);
    if ($cartReply !== null && trim($cartReply) !== '') {
        return ['reply' => trim($cartReply), 'path' => 'recover_cart'];
    }

    if ($msg === '' || preg_match('/^(hi+|hello+|hey+)\b/u', $msg)) {
        return ['reply' => "Hi! Thanks for messaging {$brand} — what can I get for you?", 'path' => 'recover_greet'];
    }

    $ai = wa_recover_openai_reply($leadId, $bot, $userMessage);
    if ($ai !== null && trim($ai) !== '') {
        return ['reply' => trim($ai), 'path' => 'recover_openai'];
    }

    return ['reply' => wa_recover_fallback_text($bot, $userMessage, $leadId), 'path' => 'recover_fallback'];
}

function wa_recover_clear_hung(int $botId = 0, int $minAgeSec = 1): int
{
    $params = [$minAgeSec];
    $botSql = '';
    if ($botId > 0) {
        $botSql = ' AND bot_id = ?';
        $params[] = $botId;
    }

    $rows = db_fetch_all(
        'SELECT id FROM conversation_turns
         WHERE status = \'processing\'
         AND processing_started_at IS NOT NULL
         AND processing_started_at < DATE_SUB(NOW(), INTERVAL ? SECOND)
         AND (ai_response_text IS NULL OR ai_response_text = \'\')' . $botSql,
        str_repeat('i', count($params)),
        $params
    ) ?: [];

    $n = 0;
    foreach ($rows as $row) {
        $turnId = (int) ($row['id'] ?? 0);
        if ($turnId <= 0) {
            continue;
        }
        db_execute(
            'UPDATE conversation_turns SET status = \'failed\', suppression_reason = \'hung_ai_timeout\',
             processing_completed_at = NOW() WHERE id = ?',
            'i',
            [$turnId]
        );
        wa_recover_log_event($turnId, 'AI_GENERATION_CANCELLED', ['reason' => 'wa_recover_lite']);
        $n++;
    }

    return $n;
}

function wa_recover_finalize_buffering(int $botId = 0): void
{
    $params = [];
    $botSql = $botId > 0 ? ' AND bot_id = ?' : '';
    if ($botId > 0) {
        $params[] = $botId;
    }

    $hold = wa_recover_diagnostic_hold_turn_ids();
    $holdSql = $hold !== []
        ? ' AND id NOT IN (' . implode(',', array_map('intval', $hold)) . ')'
        : '';

    $n = db_execute(
        'UPDATE conversation_turns SET status = \'processing\', processing_started_at = NOW(), finalize_after = NOW()
         WHERE status = \'buffering\' AND finalize_after <= NOW()' . $botSql . $holdSql,
        str_repeat('i', count($params)),
        $params
    );
    wa_recover_trace('finalize_buffering', [
        'bot_id'   => $botId,
        'affected' => $n,
        'held'     => $hold,
    ]);
}

/**
 * @param array<string, mixed> $turn
 * @param array<string, mixed> $bot
 * @return array{ok: bool, turn_id: int, lead_id: int, reply?: string, error?: string, path?: string}
 */
function wa_recover_send_turn(array $turn, array $bot, bool $useAi = true): array
{
    $turnId = (int) ($turn['id'] ?? 0);
    $leadId = (int) ($turn['lead_id'] ?? 0);
    $out = ['ok' => false, 'turn_id' => $turnId, 'lead_id' => $leadId];
    wa_recover_trace('send_turn_start', [
        'turn_id' => $turnId,
        'lead_id' => $leadId,
        'bot_id'  => (int) ($bot['id'] ?? 0),
    ]);

    if ($turnId <= 0 || $leadId <= 0) {
        $out['error'] = 'invalid turn';
        wa_recover_trace('skipped_invalid_turn', ['turn_id' => $turnId, 'lead_id' => $leadId]);

        return $out;
    }

    if (wa_recover_is_diagnostic_hold($turnId)) {
        $out['ok'] = true;
        $out['path'] = 'diagnostic_hold';
        wa_recover_trace('skipped_diagnostic_hold', [
            'turn_id' => $turnId,
            'lead_id' => $leadId,
            'reason'  => 'operator_hold_until_explicit_send',
        ]);

        return $out;
    }

    if (wa_recover_response_sent($turnId)) {
        $out['ok'] = true;
        $out['path'] = 'already_sent';
        wa_recover_trace('skipped_already_sent', ['turn_id' => $turnId, 'lead_id' => $leadId]);

        return $out;
    }

    $phoneId = trim((string) ($bot['whatsapp_phone_id'] ?? ''));
    $token = wa_recover_token_plain((string) ($bot['whatsapp_token'] ?? ''));
    wa_recover_trace('whatsapp_config', [
        'turn_id'       => $turnId,
        'lead_id'       => $leadId,
        'bot_id'        => (int) ($bot['id'] ?? 0),
        'has_phone_id'  => $phoneId !== '',
        'phone_id'      => $phoneId,
        'token_ok'      => is_string($token) && $token !== '',
        'auto_reply'    => (int) ($bot['whatsapp_auto_reply'] ?? 1),
    ]);
    if ($phoneId === '' || !is_string($token) || $token === '') {
        $out['error'] = 'whatsapp not configured';
        wa_recover_trace('skipped_whatsapp_not_configured', [
            'turn_id'      => $turnId,
            'lead_id'      => $leadId,
            'has_phone_id' => $phoneId !== '',
            'token_ok'     => is_string($token) && $token !== '',
        ]);

        return $out;
    }

    if ((int) ($bot['whatsapp_auto_reply'] ?? 1) !== 1) {
        $out['error'] = 'auto_reply_off';
        wa_recover_trace('skipped_auto_reply_off', ['turn_id' => $turnId, 'lead_id' => $leadId]);

        return $out;
    }

    $sender = trim((string) ($turn['sender_phone'] ?? ''));
    if ($sender === '') {
        $lead = db_fetch('SELECT phone, whatsapp_id FROM leads WHERE id = ?', 'i', [$leadId]);
        $sender = trim((string) ($lead['phone'] ?? $lead['whatsapp_id'] ?? ''));
    }
    if ($sender === '') {
        $out['error'] = 'no sender phone';
        wa_recover_trace('skipped_no_sender', ['turn_id' => $turnId, 'lead_id' => $leadId]);

        return $out;
    }
    wa_recover_trace('sender_resolved', [
        'turn_id'       => $turnId,
        'lead_id'       => $leadId,
        'sender_masked' => wa_recover_mask_phone($sender),
    ]);

    if ($leadId > 0) {
        wa_recover_repair_lead_turn($leadId);
        $engine = __DIR__ . '/conversation-turn-engine.php';
        if (is_readable($engine)) {
            require_once $engine;
            if (function_exists('turn_engine_consolidate_open_turns_for_lead')) {
                turn_engine_consolidate_open_turns_for_lead($leadId);
            }
            $primary = db_fetch(
                'SELECT * FROM conversation_turns
                 WHERE lead_id = ? AND NOT EXISTS (
                    SELECT 1 FROM conversation_turn_events e
                    WHERE e.turn_id = conversation_turns.id AND e.event_type = \'RESPONSE_SENT\'
                 )
                 AND EXISTS (
                    SELECT 1 FROM conversation_turn_messages ctm WHERE ctm.turn_id = conversation_turns.id
                 )
                 ORDER BY
                    CASE WHEN status IN (\'buffering\', \'processing\', \'failed\') THEN 0 ELSE 1 END,
                    id DESC
                 LIMIT 1',
                'i',
                [$leadId]
            );
            if ($primary) {
                $turn = $primary;
                $turnId = (int) ($turn['id'] ?? 0);
                if (wa_recover_is_diagnostic_hold($turnId)) {
                    $out['ok'] = true;
                    $out['path'] = 'diagnostic_hold';
                    $out['turn_id'] = $turnId;
                    wa_recover_trace('skipped_diagnostic_hold', [
                        'turn_id' => $turnId,
                        'lead_id' => $leadId,
                        'reason'  => 'operator_hold_after_consolidate',
                    ]);

                    return $out;
                }
            }
        }
    }

    $userText = wa_recover_lead_user_text($leadId, $turnId);
    try {
        $composed = wa_recover_compose_reply($leadId, $bot, $userText, $useAi);
    } catch (Throwable $e) {
        error_log('wa_recover_compose_reply #' . $turnId . ': ' . $e->getMessage());
        $composed = [
            'reply' => wa_recover_fallback_text($bot, $userText, $leadId),
            'path'  => 'recover_compose_error',
        ];
    }

    $reply = $composed['reply'];
    $path = $composed['path'];

    $sent = wa_recover_send_whatsapp($phoneId, $token, $sender, $reply);
    if (empty($sent['success'])) {
        db_execute(
            'UPDATE conversation_turns SET status = \'failed\', suppression_reason = ?, processing_completed_at = NOW() WHERE id = ?',
            'si',
            [mb_substr((string) ($sent['message'] ?? 'send_failed'), 0, 200), $turnId]
        );
        $out['error'] = (string) ($sent['message'] ?? 'send failed');
        wa_recover_trace('send_turn_end', [
            'turn_id'     => $turnId,
            'lead_id'     => $leadId,
            'ok'          => false,
            'error'       => mb_substr($out['error'], 0, 180),
            'turn_status' => 'failed',
        ]);

        return $out;
    }

    try {
        wa_recover_persist_chat($leadId, $userText, $reply);
        db_execute(
            'UPDATE conversation_turns SET status = \'completed\', ai_response_text = ?,
             processing_completed_at = NOW(), suppression_reason = ? WHERE id = ?',
            'ssi',
            [$reply, $path, $turnId]
        );
        wa_recover_log_event($turnId, 'RESPONSE_SENT', ['path' => $path, 'layer' => 'wa_recover_lite']);
        wa_recover_close_lead_turns($leadId, $turnId, $path, $reply);
        if (is_file(__DIR__ . '/conversation-runtime-memory.php')) {
            try {
                require_once __DIR__ . '/conversation-runtime-memory.php';
                conversation_runtime_remember_after_send($bot, $leadId, $userText, $reply);
            } catch (Throwable $memErr) {
                error_log('iqp_memory: recover_after_send ' . $memErr->getMessage());
            }
        }
    } catch (Throwable $e) {
        $out['error'] = 'sent but persist failed: ' . $e->getMessage();
        $out['reply'] = $reply;
        $out['path'] = $path;

        return $out;
    }

    $out['ok'] = true;
    $out['reply'] = $reply;
    $out['path'] = $path;
    wa_recover_trace('send_turn_end', [
        'turn_id'     => $turnId,
        'lead_id'     => $leadId,
        'ok'          => true,
        'path'        => $path,
        'turn_status' => 'completed',
        'chars'       => strlen($reply),
    ]);

    return $out;
}

function wa_recover_quiet_seconds(): int
{
    if (function_exists('turn_engine_quiet_seconds')) {
        return turn_engine_quiet_seconds();
    }
    $ms = defined('TURN_TEXT_DEBOUNCE_MS') ? (int) TURN_TEXT_DEBOUNCE_MS : 7000;

    return max(7, (int) ceil($ms / 1000));
}

/**
 * Re-open cancelled/failed turns so recover + webhook can reply (fixes sent:0 after merge).
 */
function wa_recover_repair_lead_turn(int $leadId): int
{
    if ($leadId <= 0) {
        return 0;
    }

    require_once __DIR__ . '/turn-schema-lite.php';
    turn_schema_lite_ensure();

    if (function_exists('turn_engine_repair_lead_turn')) {
        return turn_engine_repair_lead_turn($leadId);
    }

    $row = db_fetch(
        'SELECT t.id, t.status FROM conversation_turns t
         WHERE t.lead_id = ?
         AND EXISTS (SELECT 1 FROM conversation_turn_messages ctm WHERE ctm.turn_id = t.id)
         AND NOT EXISTS (
            SELECT 1 FROM conversation_turn_events e
            WHERE e.turn_id = t.id AND e.event_type = \'RESPONSE_SENT\'
         )
         ORDER BY t.last_message_at DESC, t.id DESC LIMIT 1',
        'i',
        [$leadId]
    );
    if (!$row) {
        return 0;
    }

    $turnId = (int) ($row['id'] ?? 0);
    if ($turnId <= 0) {
        return 0;
    }

    $sec = wa_recover_quiet_seconds();
    if ((string) ($row['status'] ?? '') !== 'buffering') {
        db_execute(
            'UPDATE conversation_turns SET
                status = \'buffering\',
                suppression_reason = NULL,
                processing_started_at = NULL,
                processing_completed_at = NULL,
                ai_response_text = NULL,
                finalize_after = DATE_ADD(NOW(), INTERVAL ? SECOND)
             WHERE id = ?',
            'ii',
            [$sec, $turnId]
        );
        wa_recover_log_event($turnId, 'TURN_REOPENED', ['reason' => 'wa_recover_repair']);
    }

    return $turnId;
}

/**
 * @return list<int>
 */
function wa_recover_leads_needing_reply(int $botId = 0, int $limit = 30): array
{
    if (function_exists('turn_engine_leads_needing_reply')) {
        return turn_engine_leads_needing_reply($botId, $limit);
    }

    require_once __DIR__ . '/turn-schema-lite.php';
    turn_schema_lite_ensure();

    $params = [];
    $botSql = $botId > 0 ? ' AND t.bot_id = ?' : '';
    if ($botId > 0) {
        $params[] = $botId;
    }
    $limit = max(1, min(100, $limit));

    $rows = db_fetch_all(
        'SELECT DISTINCT t.lead_id FROM conversation_turns t
         WHERE t.lead_id > 0
         AND EXISTS (SELECT 1 FROM conversation_turn_messages ctm WHERE ctm.turn_id = t.id)
         AND NOT EXISTS (
            SELECT 1 FROM conversation_turn_events e
            WHERE e.turn_id = t.id AND e.event_type = \'RESPONSE_SENT\'
         )' . $botSql . '
         ORDER BY t.lead_id DESC
         LIMIT ' . $limit,
        str_repeat('i', count($params)),
        $params
    ) ?: [];

    return array_values(array_unique(array_filter(array_map(
        static fn ($r) => (int) ($r['lead_id'] ?? 0),
        $rows
    ))));
}

/** True when the webhook is likely still waiting/sending this lead (do not steal). */
function wa_recover_lead_is_live(int $leadId, int $graceSec = 20): bool
{
    if ($leadId <= 0) {
        return false;
    }
    $graceSec = max(8, min(60, $graceSec));
    $row = db_fetch(
        'SELECT last_message_at, processing_started_at FROM conversation_turns
         WHERE lead_id = ? ORDER BY id DESC LIMIT 1',
        'i',
        [$leadId]
    );
    if (!$row) {
        return false;
    }
    foreach (['last_message_at', 'processing_started_at'] as $col) {
        $ts = strtotime((string) ($row[$col] ?? ''));
        if ($ts > 0 && (time() - $ts) < $graceSec) {
            return true;
        }
    }

    return false;
}

/**
 * Wait for debounce quiet — lightweight poll (no turn engine).
 *
 * @param list<int> $leadIds
 */
function wa_recover_wait_leads_quiet(array $leadIds, int $maxSec = 0): void
{
    $leadIds = array_values(array_unique(array_filter(array_map('intval', $leadIds))));
    if ($leadIds === []) {
        return;
    }

    $quietSec = wa_recover_quiet_seconds();
    $maxSec = $maxSec > 0 ? $maxSec : ($quietSec + 6);
    $deadline = time() + $maxSec;

    while (time() < $deadline) {
        $waiting = false;
        foreach ($leadIds as $leadId) {
            if ($leadId <= 0) {
                continue;
            }
            $row = db_fetch(
                'SELECT id FROM conversation_turns
                 WHERE lead_id = ? AND status = \'buffering\'
                 AND last_message_at > DATE_SUB(NOW(), INTERVAL ? SECOND)
                 LIMIT 1',
                'ii',
                [$leadId, $quietSec]
            );
            if ($row) {
                $waiting = true;
                break;
            }
        }
        if (!$waiting) {
            break;
        }
        usleep(400000);
    }
}

/**
 * @param list<int> $onlyLeadIds When set, process these leads even if recently active (webhook debounce handoff).
 * @return array{ok: bool, hung: int, sent: int, results: list<array<string, mixed>>, error?: string}
 */
function wa_recover_run(int $botId = 0, bool $useAi = true, int $limit = 1, array $onlyLeadIds = []): array
{
    require_once __DIR__ . '/turn-schema-lite.php';
    turn_schema_lite_ensure();

    $onlyLeadIds = array_values(array_unique(array_filter(array_map('intval', $onlyLeadIds))));
    $explicit = $onlyLeadIds !== [];
    $result = ['ok' => true, 'hung' => 0, 'sent' => 0, 'results' => []];

    try {
        $needed = $explicit ? $onlyLeadIds : wa_recover_leads_needing_reply($botId, 50);
        wa_recover_trace('needing_reply', [
            'bot_id'   => $botId,
            'explicit' => $explicit,
            'leads'    => $needed,
        ]);

        $result['hung'] = wa_recover_clear_hung($botId, 3);
        // Do not bulk-finalize due buffering turns before SELECT — that set
        // processing_started_at = NOW() and hid them from the 2s processing clause.

        $repairIds = $needed;
        foreach ($repairIds as $repairLeadId) {
            $repairLeadId = (int) $repairLeadId;
            $holdTurn = db_fetch(
                'SELECT id FROM conversation_turns
                 WHERE lead_id = ? AND NOT EXISTS (
                    SELECT 1 FROM conversation_turn_events e
                    WHERE e.turn_id = conversation_turns.id AND e.event_type = \'RESPONSE_SENT\'
                 )
                 ORDER BY id DESC LIMIT 1',
                'i',
                [$repairLeadId]
            );
            if ($holdTurn && wa_recover_is_diagnostic_hold((int) ($holdTurn['id'] ?? 0))) {
                wa_recover_trace('skipped_diagnostic_hold_repair', [
                    'lead_id' => $repairLeadId,
                    'turn_id' => (int) ($holdTurn['id'] ?? 0),
                ]);
                continue;
            }
            wa_recover_repair_lead_turn($repairLeadId);
        }

        $params = [];
        $types = '';
        $botSql = $botId > 0 ? ' AND t.bot_id = ?' : '';
        if ($botId > 0) {
            $params[] = $botId;
            $types .= 'i';
        }
        $leadSql = '';
        if ($explicit) {
            $leadSql = ' AND t.lead_id IN (' . implode(',', array_fill(0, count($onlyLeadIds), '?')) . ')';
            foreach ($onlyLeadIds as $id) {
                $params[] = $id;
                $types .= 'i';
            }
            $limit = max($limit, count($onlyLeadIds));
        }
        $limit = max(1, min(10, $limit));

        $leadRows = db_fetch_all(
            'SELECT t.lead_id, MAX(t.bot_id) AS bot_id, MAX(t.id) AS latest_turn_id
             FROM conversation_turns t
             WHERE t.lead_id > 0
             AND (
                t.status IN (\'failed\', \'buffering\', \'cancelled\')
                OR (
                    t.status = \'processing\'
                    AND t.processing_started_at IS NOT NULL
                    AND t.processing_started_at < DATE_SUB(NOW(), INTERVAL 2 SECOND)
                )
             )
             AND NOT EXISTS (
                SELECT 1 FROM conversation_turn_events e
                WHERE e.turn_id = t.id AND e.event_type = \'RESPONSE_SENT\'
             )' . $botSql . $leadSql . '
             GROUP BY t.lead_id
             ORDER BY latest_turn_id DESC
             LIMIT ' . $limit,
            $types,
            $params
        ) ?: [];

        wa_recover_trace('run_select', [
            'bot_id' => $botId,
            'leads'  => array_map(static fn ($r) => (int) ($r['lead_id'] ?? 0), $leadRows),
            'turns'  => array_map(static fn ($r) => (int) ($r['latest_turn_id'] ?? 0), $leadRows),
        ]);

        foreach ($leadRows as $leadRow) {
            $leadIdRow = (int) ($leadRow['lead_id'] ?? 0);
            $botIdRow = (int) ($leadRow['bot_id'] ?? 0);
            if ($leadIdRow <= 0) {
                continue;
            }
            if (!$explicit && wa_recover_lead_is_live($leadIdRow, 20)) {
                wa_recover_trace('skipped_live_webhook', ['lead_id' => $leadIdRow]);
                $result['results'][] = ['ok' => true, 'lead_id' => $leadIdRow, 'path' => 'skipped_live_webhook'];
                continue;
            }

            $holdTurnId = (int) ($leadRow['latest_turn_id'] ?? 0);
            if (wa_recover_is_diagnostic_hold($holdTurnId)) {
                wa_recover_trace('skipped_diagnostic_hold', [
                    'lead_id' => $leadIdRow,
                    'turn_id' => $holdTurnId,
                ]);
                $result['results'][] = [
                    'ok'      => true,
                    'lead_id' => $leadIdRow,
                    'turn_id' => $holdTurnId,
                    'path'    => 'diagnostic_hold',
                ];
                continue;
            }

            wa_recover_repair_lead_turn($leadIdRow);
            $engine = __DIR__ . '/conversation-turn-engine.php';
            if (is_readable($engine)) {
                require_once $engine;
                if (function_exists('turn_engine_consolidate_open_turns_for_lead')) {
                    turn_engine_consolidate_open_turns_for_lead($leadIdRow);
                }
            }

            $turn = db_fetch(
                'SELECT t.* FROM conversation_turns t
                 WHERE t.lead_id = ?
                 AND NOT EXISTS (
                    SELECT 1 FROM conversation_turn_events e
                    WHERE e.turn_id = t.id AND e.event_type = \'RESPONSE_SENT\'
                 )
                 AND EXISTS (
                    SELECT 1 FROM conversation_turn_messages ctm WHERE ctm.turn_id = t.id
                 )
                 ORDER BY
                    CASE WHEN t.status IN (\'buffering\', \'processing\', \'failed\') THEN 0 ELSE 1 END,
                    t.id DESC
                 LIMIT 1',
                'i',
                [$leadIdRow]
            );
            if (!$turn) {
                continue;
            }

            $bot = db_fetch(
                'SELECT b.*, u.company_name FROM bots b JOIN users u ON u.id = b.user_id WHERE b.id = ?',
                'i',
                [$botIdRow]
            );
            if (!$bot) {
                wa_recover_trace('skipped_bot_missing', [
                    'lead_id' => $leadIdRow,
                    'turn_id' => (int) ($turn['id'] ?? 0),
                    'bot_id'  => $botIdRow,
                ]);
                $result['results'][] = [
                    'turn_id' => (int) ($turn['id'] ?? 0),
                    'lead_id' => $leadIdRow,
                    'ok'      => false,
                    'error'   => 'bot missing',
                ];
                continue;
            }
            $phoneCheck = trim((string) ($bot['whatsapp_phone_id'] ?? ''));
            if ($phoneCheck === '') {
                wa_recover_trace('skipped_no_whatsapp_phone_id', [
                    'lead_id' => $leadIdRow,
                    'turn_id' => (int) ($turn['id'] ?? 0),
                    'bot_id'  => $botIdRow,
                ]);
                $result['results'][] = [
                    'ok'      => true,
                    'lead_id' => $leadIdRow,
                    'turn_id' => (int) ($turn['id'] ?? 0),
                    'path'    => 'no_whatsapp_phone_id',
                ];
                continue;
            }
            $one = wa_recover_send_turn($turn, $bot, $useAi);
            $result['results'][] = $one;
            $path = (string) ($one['path'] ?? '');
            if (!empty($one['ok']) && !in_array($path, ['already_sent', 'diagnostic_hold', 'no_whatsapp_phone_id'], true)) {
                $result['sent']++;
            }
        }

        if ($leadRows === [] && $botId > 0) {
            $bot = db_fetch(
                'SELECT b.*, u.company_name FROM bots b JOIN users u ON u.id = b.user_id WHERE b.id = ?',
                'i',
                [$botId]
            );
            if ($bot) {
                $awaiting = db_fetch(
                    'SELECT l.id AS lead_id, l.external_id,
                            (SELECT message FROM conversations c WHERE c.lead_id = l.id AND c.role = \'user\'
                             ORDER BY c.id DESC LIMIT 1) AS last_user_msg
                     FROM leads l
                     WHERE l.bot_id = ?
                     AND (SELECT c2.role FROM conversations c2 WHERE c2.lead_id = l.id ORDER BY c2.id DESC LIMIT 1) = \'user\'
                     ORDER BY l.id DESC LIMIT 1',
                    'i',
                    [$botId]
                );
                if ($awaiting && trim((string) ($awaiting['last_user_msg'] ?? '')) !== '') {
                    $leadId = (int) ($awaiting['lead_id'] ?? 0);
                    $openTurn = db_fetch(
                        'SELECT t.* FROM conversation_turns t
                         WHERE t.lead_id = ?
                         AND NOT EXISTS (
                            SELECT 1 FROM conversation_turn_events e
                            WHERE e.turn_id = t.id AND e.event_type = \'RESPONSE_SENT\'
                         )
                         ORDER BY t.id DESC LIMIT 1',
                        'i',
                        [$leadId]
                    );
                    if ($openTurn) {
                        $one = wa_recover_send_turn($openTurn, $bot, $useAi);
                        $result['results'][] = $one;
                        if (!empty($one['ok']) && ($one['path'] ?? '') !== 'already_sent') {
                            $result['sent']++;
                        }
                    } else {
                        $sender = trim((string) ($awaiting['external_id'] ?? ''));
                        $phoneId = trim((string) ($bot['whatsapp_phone_id'] ?? ''));
                        $token = wa_recover_token_plain((string) ($bot['whatsapp_token'] ?? ''));
                        $userText = trim((string) ($awaiting['last_user_msg'] ?? ''));
                        if ($sender !== '' && is_string($token) && $token !== '' && $phoneId !== '') {
                            $composed = wa_recover_compose_reply($leadId, $bot, $userText, $useAi);
                            $sent = wa_recover_send_whatsapp($phoneId, $token, $sender, $composed['reply']);
                            if (!empty($sent['success'])) {
                                wa_recover_persist_chat($leadId, $userText, $composed['reply']);
                                $result['results'][] = [
                                    'ok'      => true,
                                    'lead_id' => $leadId,
                                    'reply'   => $composed['reply'],
                                    'path'    => ($composed['path'] ?? 'recover') . '_awaiting',
                                ];
                                $result['sent']++;
                            }
                        }
                    }
                }
            }
        }
    } catch (Throwable $e) {
        $result['ok'] = false;
        $result['error'] = $e->getMessage();
    }

    return $result;
}
