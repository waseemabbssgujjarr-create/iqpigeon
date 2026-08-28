<?php
/**
 * Abandoned cart recovery — WhatsApp nudge when cart left idle.
 */

require_once __DIR__ . '/phase6-schema.php';
require_once __DIR__ . '/cart.php';
require_once __DIR__ . '/whatsapp.php';

function abandoned_cart_settings(int $botId, int $userId): ?array
{
    ensure_phase6_schema();
    return db_fetch(
        'SELECT * FROM bot_abandoned_cart_settings WHERE bot_id = ? AND user_id = ?',
        'ii',
        [$botId, $userId]
    );
}

function abandoned_cart_save_settings(int $botId, int $userId, array $data): void
{
    ensure_phase6_schema();

    $enabled = !empty($data['enabled']) ? 1 : 0;
    $delayHours = max(1, min(168, (int) ($data['delay_hours'] ?? 24)));
    $message = trim((string) ($data['message_body'] ?? ''));
    if ($message === '') {
        $message = "Hey! You left items in your cart 🛒\n\nReply *cart* to see them or *checkout* to complete your COD order.";
    }

    $existing = abandoned_cart_settings($botId, $userId);
    if ($existing) {
        db_execute(
            'UPDATE bot_abandoned_cart_settings SET enabled=?, delay_hours=?, message_body=? WHERE bot_id=? AND user_id=?',
            'iisii',
            [$enabled, $delayHours, $message, $botId, $userId]
        );
        return;
    }

    db_insert(
        'INSERT INTO bot_abandoned_cart_settings (bot_id, user_id, enabled, delay_hours, message_body) VALUES (?, ?, ?, ?, ?)',
        'iiiis',
        [$botId, $userId, $enabled, $delayHours, $message]
    );
}

function abandoned_cart_reset(int $leadId): void
{
    ensure_phase6_schema();
    db_execute('DELETE FROM abandoned_cart_sends WHERE lead_id = ?', 'i', [$leadId]);
}

/**
 * @return array{sent: int, skipped: int, errors: int}
 */
function abandoned_cart_process_all(): array
{
    ensure_phase6_schema();

    $stats = ['sent' => 0, 'skipped' => 0, 'errors' => 0];

    $settings = db_fetch_all(
        'SELECT s.*, b.whatsapp_phone_id, b.whatsapp_token, b.is_active AS bot_active
         FROM bot_abandoned_cart_settings s
         JOIN bots b ON b.id = s.bot_id
         WHERE s.enabled = 1 AND b.is_active = 1
           AND b.whatsapp_phone_id IS NOT NULL AND b.whatsapp_phone_id != \'\'',
        '',
        []
    );

    foreach ($settings as $setting) {
        $result = abandoned_cart_process_bot($setting);
        $stats['sent'] += $result['sent'];
        $stats['skipped'] += $result['skipped'];
        $stats['errors'] += $result['errors'];
    }

    return $stats;
}

/**
 * @param array<string, mixed> $setting
 * @return array{sent: int, skipped: int, errors: int}
 */
function abandoned_cart_process_bot(array $setting): array
{
    $stats = ['sent' => 0, 'skipped' => 0, 'errors' => 0];
    $botId = (int) $setting['bot_id'];
    $userId = (int) $setting['user_id'];
    $delayHours = max(1, (int) $setting['delay_hours']);

    $token = decrypt_token($setting['whatsapp_token'] ?? '');
    if ($token === false || $token === '') {
        return $stats;
    }
    $phoneId = (string) $setting['whatsapp_phone_id'];

    $leads = db_fetch_all(
        'SELECT l.* FROM leads l
         WHERE l.bot_id = ? AND l.platform = \'whatsapp\'
           AND l.qualification_data IS NOT NULL
           AND l.external_id IS NOT NULL AND l.external_id != \'\'',
        'i',
        [$botId]
    );

    foreach ($leads as $lead) {
        $leadId = (int) $lead['id'];
        $cart = cart_get($leadId);
        if ($cart['items'] === []) {
            $stats['skipped']++;
            continue;
        }

        $data = cart_lead_data($leadId);
        $cartMeta = $data['shop_cart'] ?? [];
        $updatedAt = strtotime((string) ($cartMeta['updated_at'] ?? $lead['updated_at'] ?? ''));
        if ($updatedAt === false || $updatedAt > time() - ($delayHours * 3600)) {
            $stats['skipped']++;
            continue;
        }

        $ordered = db_fetch(
            'SELECT id FROM bot_orders WHERE lead_id = ? AND created_at >= FROM_UNIXTIME(?) LIMIT 1',
            'ii',
            [$leadId, $updatedAt]
        );
        if ($ordered) {
            $stats['skipped']++;
            continue;
        }

        $already = db_fetch('SELECT id FROM abandoned_cart_sends WHERE lead_id = ?', 'i', [$leadId]);
        if ($already) {
            $stats['skipped']++;
            continue;
        }

        require_once __DIR__ . '/drip.php';
        if (function_exists('drip_lead_is_in_live_conversation') && drip_lead_is_in_live_conversation($leadId)) {
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

        $message = trim((string) $setting['message_body']);
        $summary = cart_format_summary($leadId);
        $fullMessage = trim($message . "\n\n" . $summary);

        $sent = send_whatsapp_message($phoneId, $token, $phone, $fullMessage);
        if (empty($sent['success'])) {
            $stats['errors']++;
            continue;
        }

        db_insert(
            'INSERT INTO abandoned_cart_sends (lead_id, bot_id) VALUES (?, ?)',
            'ii',
            [$leadId, $botId]
        );
        db_insert(
            'INSERT INTO conversations (lead_id, role, message) VALUES (?, \'assistant\', ?)',
            'is',
            [$leadId, $fullMessage]
        );
        db_execute('UPDATE leads SET updated_at = NOW() WHERE id = ?', 'i', [$leadId]);
        $stats['sent']++;
    }

    return $stats;
}
