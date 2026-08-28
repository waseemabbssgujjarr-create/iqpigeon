<?php
/**
 * Workforce Events integration + dashboard booking widgets.
 * Server dashboard and /client/event-management use event_mgmt_* names.
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/booking.php';

function event_mgmt_ensure_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $conn = db_connect();

    $conn->query(
        'CREATE TABLE IF NOT EXISTS event_mgmt_connections (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            bot_id INT UNSIGNED NOT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 0,
            api_key VARCHAR(64) NOT NULL,
            webhook_secret_enc TEXT NOT NULL,
            workforce_base_url VARCHAR(512) NULL,
            last_error TEXT NULL,
            last_ingest_at DATETIME NULL,
            events_received INT UNSIGNED NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_event_mgmt_api_key (api_key),
            UNIQUE KEY uq_event_mgmt_user (user_id),
            INDEX idx_event_mgmt_bot (bot_id),
            INDEX idx_event_mgmt_enabled (enabled)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $conn->query(
        'CREATE TABLE IF NOT EXISTS event_mgmt_traffic_log (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            connection_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            bot_id INT UNSIGNED NOT NULL,
            event_type VARCHAR(64) NOT NULL DEFAULT \'message\',
            external_event_id VARCHAR(128) NULL,
            lead_id INT UNSIGNED NULL,
            status ENUM(\'received\',\'processed\',\'failed\') NOT NULL DEFAULT \'received\',
            summary VARCHAR(512) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_event_mgmt_tl_conn (connection_id),
            INDEX idx_event_mgmt_tl_created (created_at),
            INDEX idx_event_mgmt_tl_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $done = true;
}

function event_mgmt_user_allowed(array $user): bool
{
    require_once __DIR__ . '/billing-settings.php';

    return !billing_whatsapp_blocked_for_user($user);
}

function event_mgmt_generate_api_key(): string
{
    return 'iqp_evt_' . bin2hex(random_bytes(24));
}

function event_mgmt_generate_webhook_secret(): string
{
    return bin2hex(random_bytes(32));
}

/** @return array<string, mixed>|null */
function event_mgmt_connection_for_user(int $userId): ?array
{
    if ($userId <= 0) {
        return null;
    }

    event_mgmt_ensure_schema();

    return db_fetch('SELECT * FROM event_mgmt_connections WHERE user_id = ? LIMIT 1', 'i', [$userId]);
}

/** @return array<string, mixed>|null */
function event_mgmt_connection_by_api_key(string $apiKey): ?array
{
    event_mgmt_ensure_schema();
    $apiKey = trim($apiKey);
    if ($apiKey === '') {
        return null;
    }

    return db_fetch('SELECT * FROM event_mgmt_connections WHERE api_key = ? LIMIT 1', 's', [$apiKey]);
}

function event_mgmt_plain_secret(array $connection): string
{
    $enc = (string) ($connection['webhook_secret_enc'] ?? '');
    if ($enc === '') {
        return '';
    }
    $plain = decrypt_token($enc);

    return is_string($plain) ? $plain : '';
}

/**
 * @param array<string, mixed> $input
 * @return array{connection: array<string, mixed>, rotated_key?: string, rotated_secret?: string}
 */
function event_mgmt_save_connection(int $userId, array $input): array
{
    event_mgmt_ensure_schema();

    $botId = (int) ($input['bot_id'] ?? 0);
    $owned = db_fetch('SELECT id FROM bots WHERE id = ? AND user_id = ?', 'ii', [$botId, $userId]);
    if (!$owned) {
        throw new InvalidArgumentException('Select a bot you own.');
    }

    $existing = event_mgmt_connection_for_user($userId);
    $enabled = !empty($input['enabled']) ? 1 : 0;
    $baseUrl = rtrim(trim((string) ($input['workforce_base_url'] ?? '')), '/');

    $rotatedKey = null;
    $rotatedSecret = null;

    if (!$existing) {
        $apiKey = event_mgmt_generate_api_key();
        $secret = event_mgmt_generate_webhook_secret();
        $rotatedKey = $apiKey;
        $rotatedSecret = $secret;
        $id = db_insert(
            'INSERT INTO event_mgmt_connections
              (user_id, bot_id, enabled, api_key, webhook_secret_enc, workforce_base_url)
             VALUES (?, ?, ?, ?, ?, ?)',
            'iiisss',
            [
                $userId,
                $botId,
                $enabled,
                $apiKey,
                encrypt_token($secret),
                $baseUrl !== '' ? $baseUrl : null,
            ]
        );
        $connection = db_fetch('SELECT * FROM event_mgmt_connections WHERE id = ?', 'i', [$id]);
    } else {
        $apiKey = (string) $existing['api_key'];
        $secretEnc = (string) $existing['webhook_secret_enc'];

        if (!empty($input['rotate_api_key'])) {
            $apiKey = event_mgmt_generate_api_key();
            $rotatedKey = $apiKey;
        }
        if (!empty($input['rotate_secret'])) {
            $rotatedSecret = event_mgmt_generate_webhook_secret();
            $secretEnc = encrypt_token($rotatedSecret);
        }

        db_execute(
            'UPDATE event_mgmt_connections SET
                bot_id = ?, enabled = ?, api_key = ?, webhook_secret_enc = ?,
                workforce_base_url = ?, last_error = NULL
             WHERE user_id = ?',
            'iisssi',
            [
                $botId,
                $enabled,
                $apiKey,
                $secretEnc,
                $baseUrl !== '' ? $baseUrl : null,
                $userId,
            ]
        );
        $connection = event_mgmt_connection_for_user($userId);
    }

    $out = ['connection' => $connection ?? []];
    if ($rotatedKey !== null) {
        $out['rotated_key'] = $rotatedKey;
    }
    if ($rotatedSecret !== null) {
        $out['rotated_secret'] = $rotatedSecret;
    }

    return $out;
}

/**
 * @return array{pending: int, sent: int, failed: int, today: int, received_today?: int}
 */
function event_mgmt_connection_stats(int $connectionOrUserId): array
{
    event_mgmt_ensure_schema();

    $connectionId = $connectionOrUserId;
    $byId = db_fetch('SELECT id FROM event_mgmt_connections WHERE id = ? LIMIT 1', 'i', [$connectionOrUserId]);
    if (!$byId) {
        $byUser = db_fetch('SELECT id FROM event_mgmt_connections WHERE user_id = ? LIMIT 1', 'i', [$connectionOrUserId]);
        if ($byUser) {
            $connectionId = (int) $byUser['id'];
        }
    }

    $pending = db_fetch(
        'SELECT COUNT(*) AS c FROM event_mgmt_traffic_log
         WHERE connection_id = ? AND status = \'received\'',
        'i',
        [$connectionId]
    );
    $sent = db_fetch(
        'SELECT COUNT(*) AS c FROM event_mgmt_traffic_log
         WHERE connection_id = ? AND status = \'processed\'',
        'i',
        [$connectionId]
    );
    $failed = db_fetch(
        'SELECT COUNT(*) AS c FROM event_mgmt_traffic_log
         WHERE connection_id = ? AND status = \'failed\'',
        'i',
        [$connectionId]
    );
    $today = db_fetch(
        'SELECT COUNT(*) AS c FROM event_mgmt_traffic_log
         WHERE connection_id = ? AND DATE(created_at) = CURDATE()',
        'i',
        [$connectionId]
    );

    $counts = [
        'pending'        => (int) ($pending['c'] ?? 0),
        'sent'           => (int) ($sent['c'] ?? 0),
        'failed'         => (int) ($failed['c'] ?? 0),
        'today'          => (int) ($today['c'] ?? 0),
        'received_today' => (int) ($today['c'] ?? 0),
    ];

    return $counts;
}

function event_mgmt_ingest_url(): string
{
    $base = defined('APP_URL') ? rtrim((string) APP_URL, '/') : '';

    return $base . '/api/event-mgmt-ingest.php';
}

/**
 * @return array{ok: bool, error?: string, connection?: array<string, mixed>, raw?: string, body?: array<string, mixed>}
 */
function event_mgmt_verify_ingest_request(): array
{
    event_mgmt_ensure_schema();

    $raw = file_get_contents('php://input');
    if ($raw === false) {
        $raw = '';
    }

    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/Bearer\s+(\S+)/i', $auth, $m)) {
        return ['ok' => false, 'error' => 'Missing Bearer token'];
    }

    $connection = event_mgmt_connection_by_api_key($m[1]);
    if (!$connection || empty($connection['enabled'])) {
        return ['ok' => false, 'error' => 'Invalid or disabled connection'];
    }

    $ts = (string) ($_SERVER['HTTP_X_IQP_TIMESTAMP'] ?? '');
    $sig = (string) ($_SERVER['HTTP_X_IQP_SIGNATURE'] ?? '');
    $secret = event_mgmt_plain_secret($connection);
    if ($secret === '' || $ts === '' || $sig === '') {
        return ['ok' => false, 'error' => 'Missing signature headers'];
    }

    if (abs(time() - (int) $ts) > 300) {
        return ['ok' => false, 'error' => 'Timestamp expired'];
    }

    $expected = hash_hmac('sha256', $ts . '.' . $raw, $secret);
    if (!hash_equals($expected, $sig)) {
        return ['ok' => false, 'error' => 'Invalid signature'];
    }

    $body = json_decode($raw, true);
    if (!is_array($body)) {
        return ['ok' => false, 'error' => 'Invalid JSON body'];
    }

    return ['ok' => true, 'connection' => $connection, 'raw' => $raw, 'body' => $body];
}

/**
 * @param array<string, mixed> $connection
 * @param array<string, mixed> $payload
 * @return array<string, mixed>
 */
function event_mgmt_record_traffic(array $connection, array $payload): array
{
    event_mgmt_ensure_schema();

    $eventType = trim((string) ($payload['eventType'] ?? $payload['type'] ?? 'message'));
    $externalId = trim((string) ($payload['eventId'] ?? $payload['id'] ?? ''));
    $summary = trim((string) ($payload['summary'] ?? $payload['message'] ?? ''));
    if ($summary === '') {
        $summary = $eventType !== '' ? $eventType : 'Workforce event';
    }

    $logId = db_insert(
        'INSERT INTO event_mgmt_traffic_log
          (connection_id, user_id, bot_id, event_type, external_event_id, status, summary)
         VALUES (?, ?, ?, ?, ?, \'received\', ?)',
        'iiisss',
        [
            (int) $connection['id'],
            (int) $connection['user_id'],
            (int) $connection['bot_id'],
            $eventType !== '' ? $eventType : 'message',
            $externalId !== '' ? $externalId : null,
            substr($summary, 0, 512),
        ]
    );

    db_execute(
        'UPDATE event_mgmt_connections SET events_received = events_received + 1, last_ingest_at = NOW(), last_error = NULL WHERE id = ?',
        'i',
        [(int) $connection['id']]
    );

    return ['log_id' => $logId, 'status' => 'received'];
}

/** @return array<string, mixed>|null */
function event_mgmt_primary_bot(int $userId): ?array
{
    if ($userId <= 0) {
        return null;
    }

    return db_fetch('SELECT * FROM bots WHERE user_id = ? ORDER BY id ASC LIMIT 1', 'i', [$userId]);
}

function event_mgmt_is_connected(int $userId): bool
{
    $conn = event_mgmt_connection_for_user($userId);

    return is_array($conn) && !empty($conn['enabled']);
}

function event_mgmt_booking_url(int $userId): string
{
    $bot = event_mgmt_primary_bot($userId);

    return $bot ? '/client/booking?bot_id=' . (int) $bot['id'] : '/client/booking';
}

function event_mgmt_user_has_access(int $userId): bool
{
    if ($userId <= 0) {
        return false;
    }

    try {
        $user = db_fetch('SELECT id, subscription_status FROM users WHERE id = ?', 'i', [$userId]);
        if (!$user) {
            return false;
        }

        $status = strtolower((string) ($user['subscription_status'] ?? ''));
        if (in_array($status, ['canceled', 'cancelled', 'unpaid'], true)) {
            return false;
        }
    } catch (Throwable $e) {
        error_log('event_mgmt_user_has_access: ' . $e->getMessage());
    }

    return true;
}

/** @return list<array<string, mixed>> */
function event_mgmt_upcoming(int $userId, ?int $botId = null, int $limit = 5): array
{
    return event_management_upcoming($userId, $botId, $limit);
}

/** @return array{upcoming: int, total: int} */
function event_mgmt_stats(int $userId, ?int $botId = null): array
{
    return event_management_stats($userId, $botId);
}

/** @return list<array<string, mixed>> */
function event_management_upcoming(int $userId, ?int $botId = null, int $limit = 5): array
{
    if (!event_mgmt_user_has_access($userId)) {
        return [];
    }

    $rows = booking_appointments_for_user($userId, $botId, max(1, min(50, $limit * 3)));
    $now = time();
    $upcoming = [];

    foreach ($rows as $row) {
        $start = strtotime((string) ($row['slot_start'] ?? ''));
        if ($start === false || $start < $now) {
            continue;
        }
        if (($row['status'] ?? '') === 'cancelled') {
            continue;
        }
        $upcoming[] = $row;
        if (count($upcoming) >= $limit) {
            break;
        }
    }

    return $upcoming;
}

/** @return array{upcoming: int, total: int} */
function event_management_stats(int $userId, ?int $botId = null): array
{
    if (!event_mgmt_user_has_access($userId)) {
        return ['upcoming' => 0, 'total' => 0];
    }

    ensure_commerce_schema();
    $now = date('Y-m-d H:i:s');

    if ($botId) {
        $up = db_fetch(
            'SELECT COUNT(*) AS cnt FROM bot_appointments
             WHERE user_id = ? AND bot_id = ? AND slot_start >= ? AND status <> \'cancelled\'',
            'iis',
            [$userId, $botId, $now]
        );
        $all = db_fetch(
            'SELECT COUNT(*) AS cnt FROM bot_appointments WHERE user_id = ? AND bot_id = ?',
            'ii',
            [$userId, $botId]
        );
    } else {
        $up = db_fetch(
            'SELECT COUNT(*) AS cnt FROM bot_appointments
             WHERE user_id = ? AND slot_start >= ? AND status <> \'cancelled\'',
            'is',
            [$userId, $now]
        );
        $all = db_fetch(
            'SELECT COUNT(*) AS cnt FROM bot_appointments WHERE user_id = ?',
            'i',
            [$userId]
        );
    }

    return [
        'upcoming' => (int) ($up['cnt'] ?? 0),
        'total'    => (int) ($all['cnt'] ?? 0),
    ];
}

function event_mgmt_dashboard_html(int $userId, ?int $botId = null, int $limit = 3): string
{
    if (!event_mgmt_user_has_access($userId)) {
        return '';
    }

    $events = event_mgmt_upcoming($userId, $botId, $limit);
    if ($events === []) {
        return '<p class="text-body-md text-on-surface-variant">No upcoming bookings.</p>';
    }

    $html = '<ul class="space-y-sm">';
    foreach ($events as $event) {
        $when = format_date($event['slot_start'] ?? '');
        $name = sanitize((string) ($event['customer_name'] ?? $event['lead_name'] ?? 'Guest'));
        $html .= '<li class="flex justify-between gap-sm text-body-md">'
            . '<span>' . $name . '</span>'
            . '<span class="text-outline shrink-0">' . sanitize($when) . '</span>'
            . '</li>';
    }
    $html .= '</ul>';

    return $html;
}
