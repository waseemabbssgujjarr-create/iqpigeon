<?php
/**
 * Notifications and system update subscribers.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

/**
 * @return bool
 */
function notifications_tables_ready(): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    try {
        $row = db_fetch(
            'SELECT COUNT(*) AS cnt FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = \'notifications\'',
            's',
            [DB_NAME]
        );
        $ready = ((int) ($row['cnt'] ?? 0)) > 0;
    } catch (Throwable $e) {
        $ready = false;
    }
    return $ready;
}

/**
 * Create an in-app notification for a user.
 */
function create_notification(int $userId, string $type, string $title, string $message = '', ?string $link = null): bool
{
    if (!notifications_tables_ready()) {
        return false;
    }

    $allowed = ['lead', 'system', 'billing', 'bot'];
    if (!in_array($type, $allowed, true)) {
        $type = 'system';
    }

    db_insert(
        'INSERT INTO notifications (user_id, type, title, message, link) VALUES (?, ?, ?, ?, ?)',
        'issss',
        [$userId, $type, $title, $message, $link ?? '']
    );

    return true;
}

/**
 * Notify bot owner about a new lead.
 */
function notify_new_lead(int $botId, string $leadName, string $platform, int $leadId): void
{
    $row = db_fetch(
        'SELECT b.user_id FROM bots b WHERE b.id = ?',
        'i',
        [$botId]
    );
    if (!$row) {
        return;
    }

    create_notification(
        (int) $row['user_id'],
        'lead',
        'New lead: ' . $leadName,
        'Via ' . ucfirst($platform),
        '/client/conversation?lead_id=' . $leadId
    );

    require_once __DIR__ . '/push-notifications.php';
    push_notify_user(
        (int) $row['user_id'],
        'New lead',
        $leadName . ' via ' . ucfirst($platform),
        APP_URL . '/client/conversation?lead_id=' . $leadId
    );
}

/**
 * Notify bot owner when a lead is qualified (Calendly / booking ready).
 */
function notify_lead_qualified(int $botId, string $leadName, int $leadId): void
{
    $row = db_fetch(
        'SELECT b.user_id FROM bots b WHERE b.id = ?',
        'i',
        [$botId]
    );
    if (!$row) {
        return;
    }

    $userId = (int) $row['user_id'];
    $link = '/client/conversation?lead_id=' . $leadId;

    create_notification(
        $userId,
        'lead',
        'Qualified lead: ' . $leadName,
        'Ready to book — Calendly link sent',
        $link
    );

    require_once __DIR__ . '/push-notifications.php';
    push_notify_user(
        $userId,
        'Qualified lead',
        $leadName . ' is ready to book',
        APP_URL . $link
    );
}

/**
 * Notify owner of a new WhatsApp shop order.
 */
function notify_new_order(int $userId, int $orderId, string $customerName): void
{
    $link = '/client/orders?order=' . $orderId;

    create_notification(
        $userId,
        'lead',
        'New order #' . $orderId,
        $customerName . ' — COD',
        $link
    );

    require_once __DIR__ . '/push-notifications.php';
    push_notify_user(
        $userId,
        'New order #' . $orderId,
        $customerName . ' placed a COD order',
        APP_URL . $link
    );
}

/**
 * @return int
 */
function get_unread_notification_count(int $userId): int
{
    if (!notifications_tables_ready()) {
        return 0;
    }

    try {
        $row = db_fetch(
            'SELECT COUNT(*) AS cnt FROM notifications WHERE user_id = ? AND is_read = 0',
            'i',
            [$userId]
        );

        return (int) ($row['cnt'] ?? 0);
    } catch (Throwable $e) {
        error_log('get_unread_notification_count failed: ' . $e->getMessage());
        return 0;
    }
}

/**
 * @return array<int, array<string, mixed>>
 */
function get_user_notifications(int $userId, int $limit = 20, bool $unreadOnly = false): array
{
    if (!notifications_tables_ready()) {
        return [];
    }

    try {
        $sql = 'SELECT * FROM notifications WHERE user_id = ?';
        if ($unreadOnly) {
            $sql .= ' AND is_read = 0';
        }
        $sql .= ' ORDER BY created_at DESC LIMIT ?';

        return db_fetch_all($sql, 'ii', [$userId, $limit]);
    } catch (Throwable $e) {
        error_log('get_user_notifications failed: ' . $e->getMessage());
        return [];
    }
}

function mark_notification_read(int $notificationId, int $userId): bool
{
    if (!notifications_tables_ready()) {
        return false;
    }

    return db_execute(
        'UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?',
        'ii',
        [$notificationId, $userId]
    ) > 0;
}

function mark_all_notifications_read(int $userId): void
{
    if (!notifications_tables_ready()) {
        return;
    }

    db_execute('UPDATE notifications SET is_read = 1 WHERE user_id = ?', 'i', [$userId]);
}

/**
 * Subscribe an email to product/system updates.
 *
 * @return array{success: bool, message: string}
 */
function subscribe_to_updates(string $email, string $name = '', ?int $userId = null, string $source = 'website'): array
{
    if (!notifications_tables_ready()) {
        return ['success' => false, 'message' => 'Subscription system is not ready yet.'];
    }

    $email = filter_var(trim($email), FILTER_VALIDATE_EMAIL);
    if (!$email) {
        return ['success' => false, 'message' => 'Please enter a valid email address.'];
    }

    $existing = db_fetch('SELECT id, status, token FROM update_subscribers WHERE email = ?', 's', [$email]);

    if ($existing) {
        if ($existing['status'] === 'active') {
            return ['success' => true, 'message' => 'You are already subscribed to updates.'];
        }
        db_execute(
            'UPDATE update_subscribers SET status = \'active\', name = ?, user_id = ?, source = ?, subscribed_at = NOW() WHERE id = ?',
            'sisi',
            [$name ?: $email, $userId, $source, (int) $existing['id']]
        );
        return ['success' => true, 'message' => 'Welcome back! You are subscribed again.'];
    }

    $token = generate_token(16);
    db_insert(
        'INSERT INTO update_subscribers (email, user_id, name, token, source) VALUES (?, ?, ?, ?, ?)',
        'sisss',
        [$email, $userId, $name ?: $email, $token, $source]
    );

    return ['success' => true, 'message' => 'Subscribed! You will receive product updates by email.'];
}

/**
 * @return array{success: bool, message: string}
 */
function unsubscribe_from_updates(string $token): array
{
    if (!notifications_tables_ready()) {
        return ['success' => false, 'message' => 'Invalid unsubscribe link.'];
    }

    $row = db_fetch('SELECT id FROM update_subscribers WHERE token = ? AND status = \'active\'', 's', [$token]);
    if (!$row) {
        return ['success' => false, 'message' => 'This unsubscribe link is invalid or already used.'];
    }

    db_execute('UPDATE update_subscribers SET status = \'unsubscribed\' WHERE id = ?', 'i', [(int) $row['id']]);

    return ['success' => true, 'message' => 'You have been unsubscribed from product updates.'];
}

/**
 * Unsubscribe by email (settings page).
 *
 * @return array{success: bool, message: string}
 */
function unsubscribe_email_from_updates(string $email): array
{
    if (!notifications_tables_ready()) {
        return ['success' => false, 'message' => 'Subscription system is not ready yet.'];
    }

    $email = filter_var(trim($email), FILTER_VALIDATE_EMAIL);
    if (!$email) {
        return ['success' => false, 'message' => 'Invalid email.'];
    }

    $row = db_fetch('SELECT token FROM update_subscribers WHERE email = ? AND status = \'active\'', 's', [$email]);
    if (!$row) {
        return ['success' => true, 'message' => 'You are not subscribed to email updates.'];
    }

    return unsubscribe_from_updates($row['token']);
}

/**
 * Check if email/user is subscribed.
 */
function is_subscribed_to_updates(string $email): bool
{
    if (!notifications_tables_ready()) {
        return false;
    }

    $row = db_fetch(
        'SELECT id FROM update_subscribers WHERE email = ? AND status = \'active\'',
        's',
        [$email]
    );

    return $row !== null;
}

/**
 * Publish a system update — in-app notifications + email to subscribers.
 *
 * @return array{success: bool, message: string, sent?: int}
 */
function publish_system_update(int $adminId, string $title, string $body): array
{
    if (!notifications_tables_ready()) {
        return ['success' => false, 'message' => 'Run migrate-notifications.php first.'];
    }

    $title = trim($title);
    $body = trim($body);
    if ($title === '' || $body === '') {
        return ['success' => false, 'message' => 'Title and message are required.'];
    }

    $updateId = db_insert(
        'INSERT INTO system_updates (title, body, created_by, sent_at) VALUES (?, ?, ?, NOW())',
        'ssi',
        [$title, $body, $adminId]
    );

    $link = '/client/notifications#update-' . $updateId;
    $clients = db_fetch_all('SELECT id FROM users WHERE role = \'client\'', '', []);

    foreach ($clients as $client) {
        create_notification(
            (int) $client['id'],
            'system',
            $title,
            mb_substr(strip_tags($body), 0, 120) . (strlen($body) > 120 ? '…' : ''),
            $link
        );
    }

    require_once __DIR__ . '/mailer.php';

    $subscribers = db_fetch_all(
        'SELECT email, name, token FROM update_subscribers WHERE status = \'active\'',
        '',
        []
    );

    $sent = 0;
    foreach ($subscribers as $sub) {
        if (email_system_update($sub['email'], $sub['name'], $title, $body, $sub['token'])) {
            $sent++;
        }
    }

    return [
        'success' => true,
        'message' => 'Update published to ' . count($clients) . ' users (' . $sent . ' emails sent).',
        'sent'    => $sent,
    ];
}

/**
 * Notification icon by type.
 */
function notification_icon(string $type): string
{
    switch ($type) {
        case 'lead':
            return 'person_add';
        case 'billing':
            return 'credit_card';
        case 'bot':
            return 'smart_toy';
        default:
            return 'campaign';
    }
}
