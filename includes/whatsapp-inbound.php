<?php
/**
 * WhatsApp inbound deduplication — only skip after a successful reply.
 */

require_once __DIR__ . '/db.php';

function ensure_whatsapp_inbound_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }

    try {
        db_connect()->query(
            "CREATE TABLE IF NOT EXISTS whatsapp_inbound_dedup (
                wa_message_id   VARCHAR(128) NOT NULL PRIMARY KEY,
                bot_id          INT NOT NULL,
                sender_phone    VARCHAR(32) NOT NULL,
                lead_id         INT DEFAULT NULL,
                reply_sent      TINYINT(1) NOT NULL DEFAULT 0,
                created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                replied_at      TIMESTAMP NULL DEFAULT NULL,
                KEY idx_bot_sender_created (bot_id, sender_phone, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $col = db_fetch(
            'SELECT COUNT(*) AS cnt FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = \'whatsapp_inbound_dedup\' AND COLUMN_NAME = \'reply_sent\'',
            's',
            [DB_NAME]
        );
        if ((int) ($col['cnt'] ?? 0) === 0) {
            db_connect()->query(
                'ALTER TABLE whatsapp_inbound_dedup
                 ADD COLUMN reply_sent TINYINT(1) NOT NULL DEFAULT 0 AFTER lead_id,
                 ADD COLUMN replied_at TIMESTAMP NULL DEFAULT NULL AFTER created_at'
            );
        }
    } catch (Throwable $e) {
        error_log('ensure_whatsapp_inbound_schema: ' . $e->getMessage());
    }

    $done = true;
}

function whatsapp_normalize_sender_phone(string $senderPhone): string
{
    return preg_replace('/\D/', '', $senderPhone);
}

function whatsapp_inbound_already_replied(string $waMessageId): bool
{
    ensure_whatsapp_inbound_schema();

    $waMessageId = trim($waMessageId);
    if ($waMessageId === '') {
        return false;
    }

    $row = db_fetch(
        'SELECT reply_sent FROM whatsapp_inbound_dedup WHERE wa_message_id = ? LIMIT 1',
        's',
        [$waMessageId]
    );

    return $row !== null && (int) ($row['reply_sent'] ?? 0) === 1;
}

function whatsapp_track_inbound(string $waMessageId, int $botId, string $senderPhone, int $leadId = 0): void
{
    ensure_whatsapp_inbound_schema();

    $waMessageId = trim($waMessageId);
    if ($waMessageId === '') {
        return;
    }

    try {
        db_query(
            'INSERT INTO whatsapp_inbound_dedup (wa_message_id, bot_id, sender_phone, lead_id, reply_sent)
             VALUES (?, ?, ?, ?, 0)
             ON DUPLICATE KEY UPDATE lead_id = IF(VALUES(lead_id) > 0, VALUES(lead_id), lead_id)',
            'issi',
            [$waMessageId, $botId, whatsapp_normalize_sender_phone($senderPhone), $leadId]
        )->close();
    } catch (Throwable $e) {
        error_log('whatsapp_track_inbound: ' . $e->getMessage());
    }
}

function whatsapp_mark_inbound_replied(string $waMessageId): void
{
    ensure_whatsapp_inbound_schema();

    $waMessageId = trim($waMessageId);
    if ($waMessageId === '') {
        return;
    }

    try {
        db_execute(
            'UPDATE whatsapp_inbound_dedup SET reply_sent = 1, replied_at = NOW() WHERE wa_message_id = ?',
            's',
            [$waMessageId]
        );
    } catch (Throwable $e) {
        error_log('whatsapp_mark_inbound_replied: ' . $e->getMessage());
    }
}

function whatsapp_inbound_debounce_ms(): int
{
    $ms = defined('WHATSAPP_INBOUND_DEBOUNCE_MS') ? (int) WHATSAPP_INBOUND_DEBOUNCE_MS : 2000;

    return max(0, min(4000, $ms));
}

/**
 * One reply pipeline per lead (prevents Hi / Hello / Hii → 3 replies).
 */
function whatsapp_acquire_lead_reply_lock(int $leadId, int $timeoutSeconds = 3): bool
{
    if ($leadId <= 0) {
        return false;
    }

    $row = db_fetch('SELECT GET_LOCK(?, ?) AS locked', 'si', ['wa_lead_' . $leadId, $timeoutSeconds]);

    return (int) ($row['locked'] ?? 0) === 1;
}

function whatsapp_release_lead_reply_lock(int $leadId): void
{
    if ($leadId <= 0) {
        return;
    }

    try {
        db_execute('SELECT RELEASE_LOCK(?)', 's', ['wa_lead_' . $leadId]);
    } catch (Throwable $e) {
        error_log('whatsapp_release_lead_reply_lock: ' . $e->getMessage());
    }
}

function whatsapp_mark_many_inbound_replied(array $waMessageIds): void
{
    foreach ($waMessageIds as $id) {
        if (is_string($id) && trim($id) !== '') {
            whatsapp_mark_inbound_replied($id);
        }
    }
}

/**
 * Duplicate Meta webhooks often re-deliver the same customer text with a new message id.
 * Skip if we already answered this exact user line recently.
 *
 * @param list<string> $waMessageIds Inbound Meta message ids for this batch
 */
/**
 * Strip invisible Unicode and collapse whitespace from inbound customer text.
 */
function whatsapp_normalize_inbound_text(string $text): string
{
    $text = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}\x{2060}\x{00AD}]/u', '', $text) ?? $text;
    $text = preg_replace('/\s+/u', ' ', trim($text)) ?? trim($text);

    return $text;
}

function whatsapp_should_skip_auto_reply(int $leadId, string $text, array $waMessageIds = []): bool
{
    require_once __DIR__ . '/helpers.php';
    ensure_conversations_schema();

    $text = whatsapp_normalize_inbound_text($text);
    if ($leadId <= 0 || $text === '') {
        return false;
    }

    // Greetings always get a fresh reply — avoids silence after a failed send or widget test.
    if (whatsapp_is_simple_greeting($text)) {
        return false;
    }

    // Fresh Meta message ids must always be processed (dashboard may reuse the same text).
    if ($waMessageIds !== []) {
        $tracked = false;
        foreach ($waMessageIds as $id) {
            if (!is_string($id) || trim($id) === '') {
                continue;
            }
            $tracked = true;
            if (!whatsapp_inbound_already_replied($id)) {
                return false;
            }
        }
        if ($tracked) {
            return true;
        }
    }

    $lastUser = db_fetch(
        'SELECT id, message, created_at FROM conversations
         WHERE lead_id = ? AND role = \'user\'
         ORDER BY id DESC LIMIT 1',
        'i',
        [$leadId]
    );

    if (!$lastUser || trim((string) ($lastUser['message'] ?? '')) !== $text) {
        return false;
    }

    $lastAssistant = db_fetch(
        'SELECT id FROM conversations
         WHERE lead_id = ? AND role = \'assistant\' AND id > ?
         ORDER BY id ASC LIMIT 1',
        'ii',
        [$leadId, (int) $lastUser['id']]
    );

    if (!$lastAssistant) {
        return false;
    }

    $windowSec = defined('WHATSAPP_DUPLICATE_REPLY_WINDOW_SEC')
        ? (int) WHATSAPP_DUPLICATE_REPLY_WINDOW_SEC
        : 300;

    $userAge = time() - strtotime((string) ($lastUser['created_at'] ?? 'now'));

    return $userAge < max(60, $windowSec);
}

function whatsapp_remove_unsent_assistant_turn(int $leadId, int $userConversationId): void
{
    if ($leadId <= 0 || $userConversationId <= 0) {
        return;
    }

    try {
        $row = db_fetch(
            'SELECT id FROM conversations
             WHERE lead_id = ? AND role = \'assistant\' AND id > ?
             ORDER BY id DESC LIMIT 1',
            'ii',
            [$leadId, $userConversationId]
        );
        if ($row) {
            db_execute('DELETE FROM conversations WHERE id = ?', 'i', [(int) $row['id']]);
        }
    } catch (Throwable $e) {
        error_log('whatsapp_remove_unsent_assistant_turn: ' . $e->getMessage());
    }
}

function whatsapp_lead_has_prior_reply(int $leadId): bool
{
    ensure_conversations_schema();
    if ($leadId <= 0) {
        return false;
    }

    $row = db_fetch(
        'SELECT id FROM conversations WHERE lead_id = ? AND role = \'assistant\' LIMIT 1',
        'i',
        [$leadId]
    );

    return $row !== null;
}

/** True for hi / hello / hey / salam — not wellbeing or product questions. */
function whatsapp_is_intro_greeting(string $text): bool
{
    $t = mb_strtolower(whatsapp_normalize_inbound_text($text));
    if ($t === '') {
        return false;
    }

    return preg_match(
        '/^(hi+|hello+|hey+|hiya|yo+|salam|aoa|assalamu?\s*alaikum|good (morning|afternoon|evening))[!.?\s]*$/u',
        $t
    ) === 1;
}

/** Social check-ins — must NOT trigger the canned company intro. */
function whatsapp_is_wellbeing_check(string $text): bool
{
    require_once __DIR__ . '/conversation-intent.php';

    $t = mb_strtolower(whatsapp_normalize_inbound_text($text));
    if ($t === '') {
        return false;
    }

    return preg_match(
        '/\b(how are you|how r u|how\'?re you|how\'?s it going|how do you do|how have you been|you good|are you ok|are you okay|are you there|kaise ho|kia haal)\b/u',
        conversation_normalize_casual_typos($t)
    ) === 1;
}

function whatsapp_is_simple_greeting(string $text): bool
{
    require_once __DIR__ . '/helpers.php';

    return message_is_simple_greeting(whatsapp_normalize_inbound_text($text));
}

/**
 * Last substantive user line (skips pure greetings) — for tying social replies back to the sale.
 */
function whatsapp_last_user_topic_snippet(int $leadId): string
{
    ensure_conversations_schema();
    if ($leadId <= 0) {
        return '';
    }

    $rows = db_fetch_all(
        'SELECT message FROM conversations WHERE lead_id = ? AND role = \'user\' ORDER BY id DESC LIMIT 8',
        'i',
        [$leadId]
    );

    foreach ($rows as $row) {
        $m = trim((string) ($row['message'] ?? ''));
        if ($m === '' || whatsapp_is_intro_greeting($m) || whatsapp_is_wellbeing_check($m)) {
            continue;
        }

        return mb_substr($m, 0, 160);
    }

    return '';
}

/**
 * Human short reply for greetings / wellbeing without calling AI (when turn is text-only).
 *
 * @param array<string, mixed> $bot
 */
function whatsapp_human_contextual_reply(array $bot, int $leadId, string $incomingText): ?string
{
    require_once __DIR__ . '/helpers.php';
    require_once __DIR__ . '/conversation-intent.php';

    $incomingText = whatsapp_normalize_inbound_text($incomingText);
    if ($incomingText === '') {
        return null;
    }

    $hasPrior = whatsapp_lead_has_prior_reply($leadId);
    $rep = get_bot_rep_name($bot);
    $company = trim((string) ($bot['name'] ?? ''));

    if (whatsapp_is_wellbeing_check($incomingText)) {
        return "I'm doing well, thanks for asking! How about you?";
    }

    if (message_is_simple_greeting($incomingText) && !conversation_is_identity_question($incomingText)) {
        if (!$hasPrior) {
            return whatsapp_greeting_reply($bot);
        }

        $lower = mb_strtolower($incomingText);
        if (preg_match('/morning/u', $lower)) {
            return "Morning! How's your day going?";
        }
        if (preg_match('/afternoon|evening/u', $lower)) {
            return 'Hey — good to hear from you.';
        }

        return "Hey — still here. What's up?";
    }

    return null;
}

/**
 * @param array<string, mixed> $bot
 */
function whatsapp_greeting_reply(array $bot): string
{
    require_once __DIR__ . '/helpers.php';
    $rep = get_bot_rep_name($bot);
    $company = trim((string) ($bot['name'] ?? ''));

    if ($company !== '') {
        return "Hey! I'm {$rep} from {$company} — good to hear from you.";
    }

    return "Hey! I'm {$rep} — good to hear from you.";
}

/**
 * Greeting fast-path — typing bubble, then send (never silent).
 *
 * @param array<string, mixed> $bot
 * @param array<int, string> $waMessageIds
 * @return array{success: bool, message?: string}
 */
function whatsapp_deliver_greeting_reply(
    string $phoneId,
    string $token,
    string $senderPhone,
    array $bot,
    string $incomingText,
    string $replyText,
    array $waMessageIds,
    string $waMessageId = ''
): array {
    require_once __DIR__ . '/helpers.php';
    require_once __DIR__ . '/catalog.php';
    require_once __DIR__ . '/whatsapp.php';

    $replyText = trim($replyText);
    if ($replyText === '') {
        $replyText = whatsapp_greeting_reply($bot);
    }

    if ($waMessageId !== '') {
        whatsapp_pre_reply_typing($phoneId, $token, $waMessageId);
    }

    $sent = send_whatsapp_message($phoneId, $token, $senderPhone, $replyText);
    if (!empty($sent['success'])) {
        whatsapp_mark_many_inbound_replied($waMessageIds);

        return ['success' => true];
    }

    $fallback = human_shop_fallback_reply($bot, $incomingText, 'error');
    if ($fallback !== $replyText) {
        $retry = send_whatsapp_message($phoneId, $token, $senderPhone, $fallback);
        if (!empty($retry['success'])) {
            whatsapp_mark_many_inbound_replied($waMessageIds);

            return ['success' => true, 'message' => 'Sent fallback after greeting failed'];
        }

        return ['success' => false, 'message' => (string) ($retry['message'] ?? $sent['message'] ?? 'Send failed')];
    }

    return ['success' => false, 'message' => (string) ($sent['message'] ?? 'Send failed')];
}

/**
 * Deliver an inbound auto-reply; retries with a short fallback if Meta rejects the send.
 *
 * @param array<string, mixed> $bot
 * @param array<int, string> $waMessageIds
 * @return array{success: bool, message?: string}
 */
function whatsapp_deliver_inbound_reply(
    string $phoneId,
    string $token,
    string $senderPhone,
    array $bot,
    int $leadId,
    string $incomingText,
    string $replyText,
    string $waMessageId,
    array $waMessageIds
): array {
    require_once __DIR__ . '/catalog.php';
    require_once __DIR__ . '/whatsapp.php';

    $replyText = trim($replyText);
    try {
        require_once __DIR__ . '/whatsapp-shop-ux.php';
        if (function_exists('shop_format_outgoing_message')) {
            $replyText = shop_format_outgoing_message($replyText);
        }
    } catch (Throwable $e) {
        error_log('shop_format_outgoing_message: ' . $e->getMessage());
    }

    if ($replyText === '') {
        $replyText = human_shop_fallback_reply($bot, $incomingText, 'error');
    }

    $sent = send_whatsapp_message_human($phoneId, $token, $senderPhone, $replyText, [
        'incoming_text' => $incomingText,
        'message_id'    => $waMessageId,
        'already_read'  => false,
    ]);

    if (!empty($sent['success'])) {
        whatsapp_mark_many_inbound_replied($waMessageIds);

        return ['success' => true];
    }

    if (function_exists('whatsapp_reply_debug_log')) {
        whatsapp_reply_debug_log('send_failed', [
            'bot_id'      => (int) ($bot['id'] ?? 0),
            'lead_id'     => $leadId,
            'phone_id'    => $phoneId,
            'sender'      => $senderPhone,
            'primary_err' => (string) ($sent['message'] ?? 'unknown'),
            'reply_len'   => strlen($replyText),
        ]);
    }

    $fallback = human_shop_fallback_reply($bot, $incomingText, 'error');
    if ($fallback !== $replyText) {
        $retry = send_whatsapp_message($phoneId, $token, $senderPhone, $fallback);
        if (!empty($retry['success'])) {
            whatsapp_mark_many_inbound_replied($waMessageIds);

            return ['success' => true, 'message' => 'Sent fallback after primary failed'];
        }

        return ['success' => false, 'message' => (string) ($retry['message'] ?? $sent['message'] ?? 'Send failed')];
    }

    return ['success' => false, 'message' => (string) ($sent['message'] ?? 'Send failed')];
}
