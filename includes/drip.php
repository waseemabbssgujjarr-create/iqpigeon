<?php
/**
 * Drip / follow-up sequences — auto nudge when lead goes quiet.
 */

require_once __DIR__ . '/phase5-schema.php';
require_once __DIR__ . '/whatsapp.php';

/**
 * @return array<int, array<string, mixed>>
 */
function drip_sequences_for_bot(int $botId, int $userId): array
{
    ensure_phase5_schema();
    return db_fetch_all(
        'SELECT * FROM drip_sequences WHERE bot_id = ? AND user_id = ? ORDER BY sort_order ASC, id ASC',
        'ii',
        [$botId, $userId]
    );
}

function drip_sequence_save(int $botId, int $userId, array $data, ?int $sequenceId = null): int
{
    ensure_phase5_schema();
    $name = trim((string) ($data['name'] ?? ''));
    $message = trim((string) ($data['message_body'] ?? ''));
    if ($name === '' || $message === '') {
        throw new InvalidArgumentException('Name and message are required.');
    }

    $delayHours = max(1, min(720, (int) ($data['delay_hours'] ?? 48)));
    $active = !empty($data['is_active']) ? 1 : 0;
    $sortOrder = (int) ($data['sort_order'] ?? 0);

    if ($sequenceId) {
        db_execute(
            'UPDATE drip_sequences SET name=?, delay_hours=?, message_body=?, is_active=?, sort_order=?
             WHERE id=? AND bot_id=? AND user_id=?',
            'sisiiiii',
            [$name, $delayHours, $message, $active, $sortOrder, $sequenceId, $botId, $userId]
        );
        return $sequenceId;
    }

    return db_insert(
        'INSERT INTO drip_sequences (bot_id, user_id, name, delay_hours, message_body, is_active, sort_order)
         VALUES (?, ?, ?, ?, ?, ?, ?)',
        'iisisii',
        [$botId, $userId, $name, $delayHours, $message, $active, $sortOrder]
    );
}

function drip_sequence_delete(int $sequenceId, int $botId, int $userId): void
{
    ensure_phase5_schema();
    db_execute('DELETE FROM drip_sends WHERE sequence_id = ?', 'i', [$sequenceId]);
    db_execute(
        'DELETE FROM drip_sequences WHERE id = ? AND bot_id = ? AND user_id = ?',
        'iii',
        [$sequenceId, $botId, $userId]
    );
}

/**
 * Process all active drip sequences. Called from cron.
 *
 * @return array{sent: int, skipped: int, errors: int}
 */
function drip_process_all(): array
{
    ensure_phase5_schema();

    $stats = ['sent' => 0, 'skipped' => 0, 'errors' => 0];

    $sequences = db_fetch_all(
        'SELECT d.*, b.whatsapp_phone_id, b.whatsapp_token, b.is_active AS bot_active
         FROM drip_sequences d
         JOIN bots b ON b.id = d.bot_id
         WHERE d.is_active = 1 AND b.is_active = 1
           AND b.whatsapp_phone_id IS NOT NULL AND b.whatsapp_phone_id != \'\'',
        '',
        []
    );

    foreach ($sequences as $seq) {
        $result = drip_process_sequence($seq);
        $stats['sent'] += $result['sent'];
        $stats['skipped'] += $result['skipped'];
        $stats['errors'] += $result['errors'];
    }

    return $stats;
}

/**
 * @param array<string, mixed> $sequence
 * @return array{sent: int, skipped: int, errors: int}
 */
function drip_process_sequence(array $sequence): array
{
    $stats = ['sent' => 0, 'skipped' => 0, 'errors' => 0];
    $botId = (int) $sequence['bot_id'];
    $seqId = (int) $sequence['id'];
    $delayHours = max(1, (int) $sequence['delay_hours']);
    $userId = (int) $sequence['user_id'];

    $token = decrypt_token($sequence['whatsapp_token'] ?? '');
    if ($token === false || $token === '') {
        return $stats;
    }
    $phoneId = (string) $sequence['whatsapp_phone_id'];

    // Last message from assistant, no user reply since, delay elapsed, not yet dripped for this sequence.
    $leads = db_fetch_all(
        'SELECT l.*,
            (SELECT c.role FROM conversations c WHERE c.lead_id = l.id ORDER BY c.created_at DESC LIMIT 1) AS last_role,
            (SELECT c.created_at FROM conversations c WHERE c.lead_id = l.id ORDER BY c.created_at DESC LIMIT 1) AS last_msg_at
         FROM leads l
         WHERE l.bot_id = ?
           AND l.platform = \'whatsapp\'
           AND l.status NOT IN (\'disqualified\', \'booked\')
           AND (l.bot_paused_until IS NULL OR l.bot_paused_until < NOW())
           AND l.external_id IS NOT NULL AND l.external_id != \'\'',
        'i',
        [$botId]
    );

    foreach ($leads as $lead) {
        if (($lead['last_role'] ?? '') !== 'assistant') {
            $stats['skipped']++;
            continue;
        }

        if (drip_lead_is_in_live_conversation((int) $lead['id'])) {
            $stats['skipped']++;
            continue;
        }

        $lastAt = strtotime((string) ($lead['last_msg_at'] ?? ''));
        if ($lastAt === false || $lastAt > time() - ($delayHours * 3600)) {
            $stats['skipped']++;
            continue;
        }

        $already = db_fetch(
            'SELECT id FROM drip_sends WHERE lead_id = ? AND sequence_id = ?',
            'ii',
            [(int) $lead['id'], $seqId]
        );
        if ($already) {
            $stats['skipped']++;
            continue;
        }

        if (is_lead_bot_paused($lead)) {
            $stats['skipped']++;
            continue;
        }

        $phone = preg_replace('/\D/', '', (string) $lead['external_id']);
        if ($phone === '') {
            $stats['skipped']++;
            continue;
        }

        $message = trim((string) $sequence['message_body']);
        $sent = send_whatsapp_message($phoneId, $token, $phone, $message);

        if (empty($sent['success'])) {
            $stats['errors']++;
            error_log('Drip send failed lead #' . $lead['id'] . ': ' . ($sent['message'] ?? 'unknown'));
            continue;
        }

        db_insert(
            'INSERT INTO drip_sends (lead_id, sequence_id) VALUES (?, ?)',
            'ii',
            [(int) $lead['id'], $seqId]
        );
        db_insert(
            'INSERT INTO conversations (lead_id, role, message) VALUES (?, \'assistant\', ?)',
            'is',
            [(int) $lead['id'], $message]
        );
        db_execute('UPDATE leads SET updated_at = NOW() WHERE id = ?', 'i', [(int) $lead['id']]);
        $stats['sent']++;
    }

    return $stats;
}

function drip_lead_is_in_live_conversation(int $leadId): bool
{
    if ($leadId <= 0) {
        return false;
    }
    try {
        $open = db_fetch(
            "SELECT id FROM conversation_turns
             WHERE lead_id = ? AND status IN ('buffering', 'processing')
             LIMIT 1",
            'i',
            [$leadId]
        );
        if ($open) {
            return true;
        }
        $recentUser = db_fetch(
            "SELECT created_at FROM conversations
             WHERE lead_id = ? AND role = 'user'
             ORDER BY id DESC LIMIT 1",
            'i',
            [$leadId]
        );
        if ($recentUser) {
            $at = strtotime((string) ($recentUser['created_at'] ?? ''));
            if ($at > 0 && (time() - $at) < 1200) {
                return true;
            }
        }
    } catch (Throwable $e) {
        return false;
    }

    return false;
}

/**
 * Clear drip send record when customer replies (allows re-enrollment on next cycle).
 */
function drip_reset_on_customer_reply(int $leadId): void
{
    ensure_phase5_schema();
    db_execute('DELETE FROM drip_sends WHERE lead_id = ?', 'i', [$leadId]);
}
