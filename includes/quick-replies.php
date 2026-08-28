<?php
/**
 * Saved quick replies for team — use in live conversations.
 */

require_once __DIR__ . '/phase6-schema.php';

/**
 * @return array<int, array<string, mixed>>
 */
function quick_replies_for_user(int $userId, ?int $botId = null, bool $activeOnly = true): array
{
    ensure_phase6_schema();

    $sql = 'SELECT * FROM quick_replies WHERE user_id = ?';
    $types = 'i';
    $params = [$userId];

    if ($botId !== null) {
        $sql .= ' AND (bot_id IS NULL OR bot_id = ?)';
        $types .= 'i';
        $params[] = $botId;
    }

    if ($activeOnly) {
        $sql .= ' AND is_active = 1';
    }

    $sql .= ' ORDER BY sort_order ASC, title ASC';

    return db_fetch_all($sql, $types, $params);
}

function quick_reply_save(int $userId, array $data, ?int $replyId = null): int
{
    ensure_phase6_schema();

    $title = trim((string) ($data['title'] ?? ''));
    $message = trim((string) ($data['message_body'] ?? ''));
    if ($title === '' || $message === '') {
        throw new InvalidArgumentException('Title and message are required.');
    }

    $botId = ($data['bot_id'] ?? '') === '' ? null : (int) $data['bot_id'];
    if ($botId !== null) {
        $owned = db_fetch('SELECT id FROM bots WHERE id = ? AND user_id = ?', 'ii', [$botId, $userId]);
        if (!$owned) {
            throw new InvalidArgumentException('Invalid bot.');
        }
    }

    $sortOrder = (int) ($data['sort_order'] ?? 0);
    $active = !empty($data['is_active']) ? 1 : 0;

    if ($replyId) {
        db_execute(
            'UPDATE quick_replies SET bot_id=?, title=?, message_body=?, sort_order=?, is_active=? WHERE id=? AND user_id=?',
            'issiiii',
            [$botId, $title, $message, $sortOrder, $active, $replyId, $userId]
        );
        return $replyId;
    }

    return db_insert(
        'INSERT INTO quick_replies (user_id, bot_id, title, message_body, sort_order, is_active) VALUES (?, ?, ?, ?, ?, ?)',
        'iissii',
        [$userId, $botId, $title, $message, $sortOrder, $active]
    );
}

function quick_reply_delete(int $replyId, int $userId): void
{
    ensure_phase6_schema();
    db_execute('DELETE FROM quick_replies WHERE id = ? AND user_id = ?', 'ii', [$replyId, $userId]);
}

function conversation_send_to_lead(int $leadId, int $userId, string $message): bool
{
    require_once __DIR__ . '/whatsapp.php';

    $lead = db_fetch(
        'SELECT l.*, b.user_id, b.whatsapp_phone_id, b.whatsapp_token, b.whatsapp_verified
         FROM leads l JOIN bots b ON b.id = l.bot_id
         WHERE l.id = ? AND b.user_id = ?',
        'ii',
        [$leadId, $userId]
    );
    if (!$lead) {
        return false;
    }

    pause_lead_bot($leadId, 60);
    db_insert(
        'INSERT INTO conversations (lead_id, role, message) VALUES (?, \'assistant\', ?)',
        'is',
        [$leadId, $message]
    );
    db_execute('UPDATE leads SET updated_at = NOW() WHERE id = ?', 'i', [$leadId]);

    if (($lead['platform'] ?? '') === 'whatsapp' && !empty($lead['whatsapp_verified'])) {
        $phone = preg_replace('/\D/', '', (string) ($lead['external_id'] ?? ''));
        $token = decrypt_token($lead['whatsapp_token'] ?? '');
        if ($phone !== '' && $token !== false && $token !== '' && !empty($lead['whatsapp_phone_id'])) {
            send_whatsapp_message((string) $lead['whatsapp_phone_id'], (string) $token, $phone, $message);
        }
    }

    return true;
}
