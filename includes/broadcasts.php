<?php
/**
 * WhatsApp broadcast campaigns (session + template modes).
 */

require_once __DIR__ . '/commerce-schema.php';
require_once __DIR__ . '/whatsapp.php';

function broadcast_segments(): array
{
    return [
        'all'        => 'All WhatsApp contacts',
        'active_24h' => 'Active in last 24h (session message)',
        'active_7d'  => 'Messaged in last 7 days',
        'active_30d' => 'Messaged in last 30 days',
        'qualified'  => 'Qualified / booked leads',
        'ordered'    => 'Customers with orders',
    ];
}

/**
 * @return array<int, array<string, mixed>>
 */
function broadcast_leads_for_segment(int $botId, string $segment): array
{
    ensure_commerce_schema();

    $sql = 'SELECT DISTINCT l.* FROM leads l
            WHERE l.bot_id = ? AND l.platform = \'whatsapp\'
            AND l.external_id IS NOT NULL AND l.external_id != \'\'';
    $types = 'i';
    $params = [$botId];

    switch ($segment) {
        case 'active_24h':
            $sql .= ' AND EXISTS (
                SELECT 1 FROM conversations c
                WHERE c.lead_id = l.id AND c.role = \'user\'
                AND c.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            )';
            break;
        case 'active_7d':
            $sql .= ' AND EXISTS (
                SELECT 1 FROM conversations c
                WHERE c.lead_id = l.id AND c.role = \'user\'
                AND c.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            )';
            break;
        case 'active_30d':
            $sql .= ' AND EXISTS (
                SELECT 1 FROM conversations c
                WHERE c.lead_id = l.id AND c.role = \'user\'
                AND c.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            )';
            break;
        case 'qualified':
            $sql .= ' AND l.status IN (\'qualified\', \'booked\')';
            break;
        case 'ordered':
            $sql .= ' AND EXISTS (SELECT 1 FROM bot_orders o WHERE o.lead_id = l.id)';
            break;
        default:
            break;
    }

    $sql .= ' ORDER BY l.updated_at DESC LIMIT 5000';

    return db_fetch_all($sql, $types, $params);
}

function broadcast_lead_in_session_window(int $leadId): bool
{
    $row = db_fetch(
        'SELECT COUNT(*) AS cnt FROM conversations
         WHERE lead_id = ? AND role = \'user\' AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)',
        'i',
        [$leadId]
    );
    return (int) ($row['cnt'] ?? 0) > 0;
}

/**
 * @return array<int, array<string, mixed>>
 */
function broadcast_list_for_user(int $userId, ?int $botId = null, int $limit = 30): array
{
    ensure_commerce_schema();
    if ($botId) {
        return db_fetch_all(
            'SELECT * FROM broadcasts WHERE user_id = ? AND bot_id = ? ORDER BY created_at DESC LIMIT ' . max(1, min(100, $limit)),
            'ii',
            [$userId, $botId]
        );
    }
    return db_fetch_all(
        'SELECT * FROM broadcasts WHERE user_id = ? ORDER BY created_at DESC LIMIT ' . max(1, min(100, $limit)),
        'i',
        [$userId]
    );
}

function broadcast_create(int $botId, int $userId, array $data): int
{
    ensure_commerce_schema();

    $segment = (string) ($data['segment'] ?? 'all');
    if (!array_key_exists($segment, broadcast_segments())) {
        $segment = 'all';
    }

    $sendMode = ($data['send_mode'] ?? 'session') === 'template' ? 'template' : 'session';
    $title = trim((string) ($data['title'] ?? 'Broadcast'));
    $body = trim((string) ($data['message_body'] ?? ''));
    if ($body === '') {
        throw new InvalidArgumentException('Message is required.');
    }

    $leads = broadcast_leads_for_segment($botId, $segment);

    $broadcastId = db_insert(
        'INSERT INTO broadcasts (bot_id, user_id, title, message_body, segment, send_mode, template_name, template_lang, status, total_recipients)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, \'draft\', ?)',
        'iissssssi',
        [
            $botId,
            $userId,
            $title !== '' ? $title : 'Broadcast',
            $body,
            $segment,
            $sendMode,
            trim((string) ($data['template_name'] ?? '')) ?: null,
            trim((string) ($data['template_lang'] ?? 'en')) ?: 'en',
            count($leads),
        ]
    );

    foreach ($leads as $lead) {
        $phone = preg_replace('/\D/', '', (string) ($lead['external_id'] ?? ''));
        if ($phone === '') {
            continue;
        }
        db_insert(
            'INSERT INTO broadcast_recipients (broadcast_id, lead_id, phone, status) VALUES (?, ?, ?, \'pending\')',
            'iis',
            [$broadcastId, (int) $lead['id'], $phone]
        );
    }

    db_execute(
        'UPDATE broadcasts SET total_recipients = (SELECT COUNT(*) FROM broadcast_recipients WHERE broadcast_id = ?) WHERE id = ?',
        'ii',
        [$broadcastId, $broadcastId]
    );

    return $broadcastId;
}

/**
 * Send up to $batchSize pending messages for a broadcast.
 *
 * @return array{sent: int, failed: int, skipped: int, done: bool, errors: array<int, string>}
 */
function broadcast_send_batch(int $broadcastId, int $userId, int $batchSize = 25): array
{
    ensure_commerce_schema();

    $broadcast = db_fetch(
        'SELECT b.* FROM broadcasts b WHERE b.id = ? AND b.user_id = ?',
        'ii',
        [$broadcastId, $userId]
    );
    if (!$broadcast) {
        return ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'done' => true, 'errors' => ['Broadcast not found.']];
    }

    $creds = whatsapp_bot_credentials((int) $broadcast['bot_id'], $userId);
    if (!$creds) {
        return ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'done' => true, 'errors' => ['WhatsApp not connected for this bot.']];
    }

    if ($broadcast['status'] === 'draft') {
        db_execute('UPDATE broadcasts SET status = \'sending\' WHERE id = ?', 'i', [$broadcastId]);
    }

    $recipients = db_fetch_all(
        'SELECT * FROM broadcast_recipients WHERE broadcast_id = ? AND status = \'pending\' LIMIT ' . max(1, min(50, $batchSize)),
        'i',
        [$broadcastId]
    );

    $sent = 0;
    $failed = 0;
    $skipped = 0;
    $errors = [];

    foreach ($recipients as $rec) {
        $phone = (string) $rec['phone'];
        $leadId = (int) ($rec['lead_id'] ?? 0);

        if ($broadcast['send_mode'] === 'session') {
            if ($leadId > 0 && !broadcast_lead_in_session_window($leadId)) {
                db_execute(
                    'UPDATE broadcast_recipients SET status = \'skipped\', error_message = ? WHERE id = ?',
                    'si',
                    ['Outside 24h window — use template mode', (int) $rec['id']]
                );
                $skipped++;
                continue;
            }
            $result = send_whatsapp_message($creds['phone_id'], $creds['token'], $phone, (string) $broadcast['message_body']);
        } else {
            $template = trim((string) ($broadcast['template_name'] ?? ''));
            if ($template === '') {
                $result = ['success' => false, 'message' => 'Template name required'];
            } else {
                $result = send_whatsapp_template(
                    $creds['phone_id'],
                    $creds['token'],
                    $phone,
                    $template,
                    (string) ($broadcast['template_lang'] ?? 'en'),
                    [(string) $broadcast['message_body']]
                );
            }
        }

        if (!empty($result['success'])) {
            db_execute(
                'UPDATE broadcast_recipients SET status = \'sent\', sent_at = NOW() WHERE id = ?',
                'i',
                [(int) $rec['id']]
            );
            if ($leadId > 0) {
                db_insert(
                    'INSERT INTO conversations (lead_id, role, message) VALUES (?, \'assistant\', ?)',
                    'is',
                    [$leadId, '[Broadcast] ' . (string) $broadcast['message_body']]
                );
            }
            $sent++;
        } else {
            $err = (string) ($result['message'] ?? 'Send failed');
            db_execute(
                'UPDATE broadcast_recipients SET status = \'failed\', error_message = ? WHERE id = ?',
                'si',
                [mb_substr($err, 0, 500), (int) $rec['id']]
            );
            $failed++;
            if (count($errors) < 5) {
                $errors[] = $phone . ': ' . $err;
            }
        }

        usleep(350000);
    }

    $stats = db_fetch(
        'SELECT
            SUM(status = \'sent\') AS sent,
            SUM(status = \'failed\') AS failed,
            SUM(status = \'skipped\') AS skipped,
            SUM(status = \'pending\') AS pending
         FROM broadcast_recipients WHERE broadcast_id = ?',
        'i',
        [$broadcastId]
    );

    $pending = (int) ($stats['pending'] ?? 0);
    $done = $pending === 0;

    db_execute(
        'UPDATE broadcasts SET sent_count = ?, failed_count = ?, status = ?, completed_at = IF(?, NOW(), completed_at) WHERE id = ?',
        'iisii',
        [
            (int) ($stats['sent'] ?? 0),
            (int) ($stats['failed'] ?? 0) + (int) ($stats['skipped'] ?? 0),
            $done ? 'completed' : 'sending',
            $done ? 1 : 0,
            $broadcastId,
        ]
    );

    return [
        'sent'    => $sent,
        'failed'  => $failed,
        'skipped' => $skipped,
        'done'    => $done,
        'errors'  => $errors,
    ];
}

function broadcast_recipient_stats(int $broadcastId): array
{
    ensure_commerce_schema();
    return db_fetch(
        'SELECT
            COUNT(*) AS total,
            SUM(status = \'sent\') AS sent,
            SUM(status = \'failed\') AS failed,
            SUM(status = \'skipped\') AS skipped,
            SUM(status = \'pending\') AS pending
         FROM broadcast_recipients WHERE broadcast_id = ?',
        'i',
        [$broadcastId]
    ) ?: [];
}
