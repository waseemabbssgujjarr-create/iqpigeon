<?php
/**
 * WhatsApp token storage, validation, and proactive health checks.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

function ensure_whatsapp_token_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $columns = [
        'whatsapp_token_app_id'     => 'VARCHAR(32) NULL',
        'whatsapp_token_expires_at' => 'DATETIME NULL',
        'whatsapp_token_checked_at' => 'DATETIME NULL',
        'whatsapp_token_error'      => 'VARCHAR(500) NULL',
    ];

    foreach ($columns as $column => $definition) {
        if (!db_column_exists('bots', $column)) {
            try {
                db_connect()->query("ALTER TABLE bots ADD COLUMN {$column} {$definition}");
            } catch (Throwable $e) {
                error_log('ensure_whatsapp_token_schema ' . $column . ': ' . $e->getMessage());
            }
        }
    }

    try {
        db_connect()->query(
            'CREATE TABLE IF NOT EXISTS whatsapp_ai_rate (
                bot_id INT NOT NULL,
                minute_bucket VARCHAR(16) NOT NULL,
                call_count INT NOT NULL DEFAULT 0,
                PRIMARY KEY (bot_id, minute_bucket),
                KEY idx_bucket (minute_bucket)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    } catch (Throwable $e) {
        error_log('ensure_whatsapp_ai_rate_schema: ' . $e->getMessage());
    }

    $done = true;
}

/**
 * Decrypt stored token, or accept legacy plaintext Meta tokens (EAA...).
 */
function bot_whatsapp_token_plain(string $stored): string|false
{
    $stored = trim($stored);
    if ($stored === '') {
        return false;
    }

    $plain = decrypt_token($stored);
    if (is_string($plain) && $plain !== '') {
        return $plain;
    }

    if (preg_match('/^EAA[A-Za-z0-9]+$/', $stored) === 1) {
        return $stored;
    }

    return false;
}

/**
 * Diagnose why a stored token cannot be read (for debug UI — never returns full plaintext).
 *
 * @return array<string, mixed>
 */
function whatsapp_diagnose_stored_token(string $stored): array
{
    $stored = trim($stored);
    $info = [
        'stored_length'      => strlen($stored),
        'stored_empty'       => $stored === '',
        'looks_plain_eaa'    => preg_match('/^EAA[A-Za-z0-9]+$/', $stored) === 1,
        'valid_base64'       => false,
        'decoded_bytes'      => 0,
        'decrypt_ok'         => false,
        'plain_prefix'       => '',
        'plain_length'       => 0,
        'openssl_loaded'     => extension_loaded('openssl'),
        'encrypt_key_set'    => defined('ENCRYPT_KEY') && ENCRYPT_KEY !== '',
        'encryption_key_set' => defined('ENCRYPTION_KEY') && ENCRYPTION_KEY !== '',
        'keys_match'         => defined('ENCRYPT_KEY') && defined('ENCRYPTION_KEY') && ENCRYPT_KEY === ENCRYPTION_KEY,
        'key_fingerprint'    => defined('ENCRYPT_KEY') ? substr(hash('sha256', ENCRYPT_KEY), 0, 12) : '',
        'issue'              => '',
    ];

    if ($stored === '') {
        $info['issue'] = 'Token column is empty — reconnect WhatsApp via Bot Setup → Channels.';

        return $info;
    }

    if ($info['looks_plain_eaa']) {
        $info['decrypt_ok'] = true;
        $info['plain_prefix'] = substr($stored, 0, 8) . '…';
        $info['plain_length'] = strlen($stored);
        $info['issue'] = 'Stored as plain EAA token (OK — will be re-encrypted on repair).';

        return $info;
    }

    $decoded = base64_decode($stored, true);
    $info['valid_base64'] = $decoded !== false;
    $info['decoded_bytes'] = is_string($decoded) ? strlen($decoded) : 0;

    if (!$info['valid_base64']) {
        $info['issue'] = 'Stored value is not valid base64 — token corrupted in database. Reconnect WhatsApp.';

        return $info;
    }

    if ($info['decoded_bytes'] < 17) {
        $info['issue'] = 'Encrypted blob too short (' . $info['decoded_bytes'] . ' bytes) — token truncated. Reconnect WhatsApp.';

        return $info;
    }

    if (!$info['encrypt_key_set'] && !$info['encryption_key_set']) {
        $info['issue'] = 'ENCRYPTION_KEY is not defined in config — tokens cannot be decrypted.';

        return $info;
    }

    $plain = decrypt_token($stored);
    if (is_string($plain) && $plain !== '') {
        $info['decrypt_ok'] = true;
        $info['plain_prefix'] = substr($plain, 0, 8) . '…';
        $info['plain_length'] = strlen($plain);
        $info['issue'] = 'Decrypt OK';

        return $info;
    }

    $info['issue'] = 'Decrypt failed — ENCRYPTION_KEY on server likely changed since connect. '
        . 'Keep the same ENCRYPTION_KEY in config.local.php, then Disconnect + Connect WhatsApp again.';

    return $info;
}

/**
 * Plain Meta access token for an embedded-signup client (account row, bot fallback, optional re-encrypt repair).
 */
function whatsapp_client_access_token(int $clientId, bool $repair = false): string|false
{
    $account = db_fetch(
        'SELECT id, business_token, phone_number_id FROM client_whatsapp_accounts
         WHERE client_id = ? AND connection_status = \'active\'
         ORDER BY connected_at DESC LIMIT 1',
        'i',
        [$clientId]
    );

    $phoneNumberId = (string) ($account['phone_number_id'] ?? '');
    $plain = false;

    if ($account) {
        $plain = bot_whatsapp_token_plain((string) ($account['business_token'] ?? ''));
    }

    $bot = null;
    if ($plain === false || $plain === '') {
        if ($phoneNumberId !== '') {
            $bot = db_fetch(
                'SELECT id, whatsapp_token, whatsapp_phone_id FROM bots
                 WHERE user_id = ? AND whatsapp_phone_id = ? LIMIT 1',
                'is',
                [$clientId, $phoneNumberId]
            );
        }
        if (!$bot) {
            $bot = db_fetch(
                'SELECT id, whatsapp_token, whatsapp_phone_id FROM bots
                 WHERE user_id = ? AND whatsapp_phone_id != \'\' AND whatsapp_verified = 1
                 ORDER BY id ASC LIMIT 1',
                'i',
                [$clientId]
            );
        }
        if ($bot) {
            $plain = bot_whatsapp_token_plain((string) ($bot['whatsapp_token'] ?? ''));
        }
    }

    if ($plain === false || $plain === '') {
        return false;
    }

    if ($repair) {
        $encrypted = encrypt_token($plain);
        if ($account) {
            db_execute(
                'UPDATE client_whatsapp_accounts SET business_token = ? WHERE id = ?',
                'si',
                [$encrypted, (int) $account['id']]
            );
        }
        $syncPhoneId = $phoneNumberId !== '' ? $phoneNumberId : (string) ($bot['whatsapp_phone_id'] ?? '');
        if ($syncPhoneId !== '') {
            $bots = db_fetch_all('SELECT id FROM bots WHERE user_id = ?', 'i', [$clientId]);
            foreach ($bots as $row) {
                db_execute(
                    'UPDATE bots SET whatsapp_phone_id = ?, whatsapp_token = ?, whatsapp_verified = 1, whatsapp_token_error = NULL WHERE id = ?',
                    'ssi',
                    [$syncPhoneId, $encrypted, (int) $row['id']]
                );
            }
        }
    }

    return $plain;
}

/**
 * True when phone ID is set and the stored token can be read.
 */
function bot_whatsapp_token_is_healthy(array $bot): bool
{
    if (empty($bot['whatsapp_phone_id'])) {
        return false;
    }

    $plain = bot_whatsapp_token_plain((string) ($bot['whatsapp_token'] ?? ''));

    return $plain !== false && $plain !== '';
}

/**
 * Read-only connection status for UI (does not modify the token).
 *
 * @return array{connected: bool, error: string, masked: string}
 */
function bot_whatsapp_connection_status(array $bot): array
{
    $storedError = trim((string) ($bot['whatsapp_token_error'] ?? ''));
    $masked = bot_whatsapp_token_masked($bot);
    $hasPhone = trim((string) ($bot['whatsapp_phone_id'] ?? '')) !== '';
    $tokenReadable = bot_whatsapp_token_is_healthy($bot);
    $verified = (int) ($bot['whatsapp_verified'] ?? 0) === 1;

    if (!$hasPhone || !$tokenReadable) {
        return [
            'connected' => false,
            'error'     => $storedError !== ''
                ? $storedError
                : (!$hasPhone
                    ? 'Phone Number ID not saved yet.'
                    : 'Could not read saved token — click Replace token, paste a new Meta token, then Verify & Connect.'),
            'masked'    => $masked,
        ];
    }

    if (!$verified || $storedError !== '') {
        return [
            'connected' => false,
            'error'     => $storedError !== ''
                ? $storedError
                : 'Token saved but not verified with Meta yet. Paste a fresh token and click Verify & Connect.',
            'masked'    => $masked,
        ];
    }

    return ['connected' => true, 'error' => '', 'masked' => $masked];
}

/**
 * Mask token for display (never expose full secret in HTML).
 */
function bot_whatsapp_token_masked(array $bot): string
{
    $plain = bot_whatsapp_token_plain((string) ($bot['whatsapp_token'] ?? ''));
    if ($plain === false || $plain === '') {
        return '';
    }
    $len = strlen($plain);
    if ($len <= 12) {
        return str_repeat('•', $len);
    }

    return substr($plain, 0, 8) . str_repeat('•', min(16, $len - 12)) . substr($plain, -4);
}

/**
 * Re-encrypt legacy plaintext tokens only. Does not mark verified — use after successful verify or send.
 */
function bot_whatsapp_heal_connection(int $botId): void
{
    ensure_whatsapp_token_schema();

    $bot = db_fetch('SELECT * FROM bots WHERE id = ?', 'i', [$botId]);
    if (!$bot || !bot_whatsapp_token_is_healthy($bot)) {
        return;
    }

    $storedRaw = trim((string) ($bot['whatsapp_token'] ?? ''));
    $plain = bot_whatsapp_token_plain($storedRaw);

    if ($plain !== false && preg_match('/^EAA[A-Za-z0-9]+$/', $storedRaw) === 1) {
        db_execute(
            'UPDATE bots SET whatsapp_token = ? WHERE id = ?',
            'si',
            [encrypt_token($plain), $botId]
        );
    }
}

/** Mark WhatsApp verified after a successful Meta API check or outbound send. */
function bot_whatsapp_mark_verified(int $botId): void
{
    ensure_whatsapp_token_schema();
    db_execute(
        'UPDATE bots SET whatsapp_verified = 1, whatsapp_token_error = NULL, whatsapp_token_checked_at = NOW() WHERE id = ?',
        'i',
        [$botId]
    );
}

/**
 * @deprecated Use bot_whatsapp_connection_status() + bot_whatsapp_heal_connection()
 * @return array{connected: bool, error: string}
 */
function bot_whatsapp_sync_connection(int $botId): array
{
    $bot = db_fetch('SELECT * FROM bots WHERE id = ?', 'i', [$botId]);
    if (!$bot) {
        return ['connected' => false, 'error' => ''];
    }

    $status = bot_whatsapp_connection_status($bot);
    if ($status['connected']) {
        bot_whatsapp_mark_verified($botId);
    }

    return ['connected' => $status['connected'], 'error' => $status['error']];
}

function whatsapp_app_id_mismatch_message(string $tokenAppId, ?string $configAppId = null): string
{
    $configAppId = $configAppId ?? (defined('META_APP_ID') ? (string) META_APP_ID : '');

    return 'This token belongs to Meta app ' . $tokenAppId
        . ' but this site is configured for app ' . $configAppId
        . '. Update config.php META_APP_ID + META_APP_SECRET to match the app where you created the token'
        . ' (see the app ID in your Meta dashboard URL), then paste the token again.';
}

/**
 * @return array{success: false, message: string, token_app_id?: string, config_app_id?: string}|null
 */
function whatsapp_check_token_app_match(string $token): ?array
{
    if (!function_exists('whatsapp_inspect_token')) {
        return null;
    }

    $inspect = whatsapp_inspect_token($token);
    $tokenAppId = trim((string) ($inspect['app_id'] ?? ''));
    $configAppId = defined('META_APP_ID') ? trim((string) META_APP_ID) : '';

    if ($tokenAppId === '' || $configAppId === '') {
        return null;
    }

    if ($tokenAppId !== $configAppId) {
        return [
            'success'       => false,
            'message'       => whatsapp_app_id_mismatch_message($tokenAppId, $configAppId),
            'token_app_id'  => $tokenAppId,
            'config_app_id' => $configAppId,
        ];
    }

    return null;
}

function whatsapp_token_expiry_warning(array $inspect): string
{
    $expiresAt = (int) ($inspect['expires_at'] ?? 0);
    if ($expiresAt <= 0) {
        return '';
    }

    $daysLeft = (int) floor(($expiresAt - time()) / 86400);
    if ($daysLeft <= 0) {
        return ' Warning: this token is already expired.';
    }
    if ($daysLeft <= 7) {
        return ' Warning: token expires in ' . $daysLeft . ' day(s). Use a System User token with Never expire.';
    }

    return '';
}

/**
 * Disconnect this Phone Number ID from every bot except the one that just connected.
 */
function bot_whatsapp_release_phone_id(int $keepBotId, string $phoneId): int
{
    if ($phoneId === '' || $keepBotId <= 0) {
        return 0;
    }

    ensure_whatsapp_token_schema();

    $conflicts = db_fetch_all(
        'SELECT id, name, user_id FROM bots WHERE whatsapp_phone_id = ? AND id != ?',
        'si',
        [$phoneId, $keepBotId]
    );

    if ($conflicts === []) {
        return 0;
    }

    db_execute(
        'UPDATE bots SET whatsapp_phone_id = NULL, whatsapp_token = NULL, whatsapp_verified = 0,
            whatsapp_token_error = ?
         WHERE whatsapp_phone_id = ? AND id != ?',
        'ssi',
        ['Disconnected — this WhatsApp number was linked to another bot.', $phoneId, $keepBotId]
    );

    foreach ($conflicts as $row) {
        error_log(sprintf(
            'WhatsApp phone_id %s released from bot #%d (%s) — now owned by bot #%d',
            $phoneId,
            (int) $row['id'],
            (string) ($row['name'] ?? ''),
            $keepBotId
        ));
    }

    return count($conflicts);
}

/**
 * Bots sharing the same Meta Phone Number ID (routing conflict).
 *
 * @return list<array<string, mixed>>
 */
function bot_whatsapp_phone_id_conflicts(string $phoneId, int $excludeBotId = 0): array
{
    if ($phoneId === '') {
        return [];
    }

    if ($excludeBotId > 0) {
        return db_fetch_all(
            'SELECT b.id, b.name, b.user_id, b.is_active, u.email, u.company_name
             FROM bots b
             JOIN users u ON u.id = b.user_id
             WHERE b.whatsapp_phone_id = ? AND b.id != ?
             ORDER BY b.id ASC',
            'si',
            [$phoneId, $excludeBotId]
        );
    }

    return db_fetch_all(
        'SELECT b.id, b.name, b.user_id, b.is_active, u.email, u.company_name
         FROM bots b
         JOIN users u ON u.id = b.user_id
         WHERE b.whatsapp_phone_id = ?
         ORDER BY b.id ASC',
        's',
        [$phoneId]
    );
}

/**
 * Resolve which bot receives inbound WhatsApp for a Phone Number ID.
 *
 * @return array<string, mixed>|null
 */
function bot_resolve_by_whatsapp_phone_id(string $phoneId): ?array
{
    if ($phoneId === '') {
        return null;
    }

    if (!function_exists('ensure_bot_training_schema')) {
        require_once __DIR__ . '/bot-knowledge.php';
    }
    ensure_bot_training_schema();

    $orderBy = db_column_exists('bots', 'knowledge_updated_at')
        ? 'ORDER BY COALESCE(b.knowledge_updated_at, b.created_at) DESC, b.id DESC'
        : 'ORDER BY b.id DESC';

    $bot = db_fetch(
        'SELECT b.*, u.company_name, u.email AS client_email
         FROM bots b
         JOIN users u ON u.id = b.user_id
         WHERE b.whatsapp_phone_id = ? AND b.is_active = 1
         ' . $orderBy . '
         LIMIT 1',
        's',
        [$phoneId]
    );

    return $bot ?: null;
}

/**
 * Persist encrypted token + metadata after successful connect.
 */
function bot_whatsapp_token_save(int $botId, int $userId, string $phoneId, string $plainToken): bool
{
    ensure_whatsapp_token_schema();

    bot_whatsapp_release_phone_id($botId, $phoneId);

    $inspect = function_exists('whatsapp_inspect_token') ? whatsapp_inspect_token($plainToken) : [];
    $expiresAt = null;
    $expiresUnix = (int) ($inspect['expires_at'] ?? 0);
    if ($expiresUnix > 0) {
        $expiresAt = date('Y-m-d H:i:s', $expiresUnix);
    }

    db_execute(
        'UPDATE bots SET whatsapp_phone_id = ?, whatsapp_token = ?, whatsapp_verified = 1,
            whatsapp_token_app_id = ?, whatsapp_token_checked_at = NOW(), whatsapp_token_error = NULL
         WHERE id = ? AND user_id = ?',
        'sssii',
        [
            $phoneId,
            encrypt_token($plainToken),
            (string) ($inspect['app_id'] ?? ''),
            $botId,
            $userId,
        ]
    );

    if ($expiresAt !== null) {
        db_execute(
            'UPDATE bots SET whatsapp_token_expires_at = ? WHERE id = ?',
            'si',
            [$expiresAt, $botId]
        );
    }

    bot_whatsapp_mark_verified($botId);

    return true;
}

function whatsapp_note_send_failure(int $botId, string $errorMessage): void
{
    $lower = strtolower($errorMessage);
    $critical = str_contains($lower, 'expired')
        || str_contains($lower, 'invalid oauth')
        || str_contains($lower, 'cannot call api for app')
        || str_contains($lower, 'error validating access token')
        || str_contains($lower, 'session has expired');

    if (!$critical) {
        return;
    }

    whatsapp_mark_token_failure($botId, $errorMessage);

    if (!function_exists('send_email') || !defined('ADMIN_EMAIL') || ADMIN_EMAIL === '') {
        return;
    }

    send_email(
        ADMIN_EMAIL,
        'WhatsApp token failed — bot #' . $botId,
        email_template(
            'WhatsApp send failed',
            '<p>Bot #' . (int) $botId . ' was marked disconnected:</p><p><code>'
            . htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8')
            . '</code></p><p>Client must reconnect in Bot Setup with a System User permanent token.</p>',
            APP_URL . '/admin/dashboard',
            'Open admin'
        )
    );
}

function whatsapp_mark_token_failure(int $botId, string $reason): void
{
    ensure_whatsapp_token_schema();

    $reason = mb_substr(trim($reason), 0, 490);
    db_execute(
        'UPDATE bots SET whatsapp_verified = 0, whatsapp_token_error = ?, whatsapp_token_checked_at = NOW() WHERE id = ?',
        'si',
        [$reason, $botId]
    );
}

function whatsapp_clear_token_error(int $botId): void
{
    if (!db_column_exists('bots', 'whatsapp_token_error')) {
        return;
    }
    db_execute('UPDATE bots SET whatsapp_token_error = NULL WHERE id = ?', 'i', [$botId]);
}

/**
 * @return array{bot_id: int, ok: bool, action: string, message: string}[]
 */
function whatsapp_process_token_health_all(): array
{
    require_once __DIR__ . '/whatsapp.php';
    require_once __DIR__ . '/mailer.php';

    ensure_whatsapp_token_schema();

    $bots = db_fetch_all(
        'SELECT b.id, b.name, b.whatsapp_phone_id, b.whatsapp_token, b.whatsapp_verified,
                u.email AS client_email, u.company_name
         FROM bots b
         JOIN users u ON u.id = b.user_id
         WHERE b.whatsapp_phone_id != \'\' AND b.whatsapp_token != \'\'',
        '',
        []
    );

    $results = [];

    foreach ($bots as $bot) {
        $botId = (int) $bot['id'];
        $plain = bot_whatsapp_token_plain((string) ($bot['whatsapp_token'] ?? ''));

        if ($plain === false || $plain === '') {
            whatsapp_mark_token_failure($botId, 'Could not read saved token — reconnect in Bot Setup.');
            $results[] = [
                'bot_id'  => $botId,
                'ok'      => false,
                'action'  => 'marked_disconnected',
                'message' => 'Token decrypt failed',
            ];
            continue;
        }

        $verify = verify_whatsapp_credentials((string) $bot['whatsapp_phone_id'], $plain);
        if (!$verify['success']) {
            whatsapp_mark_token_failure($botId, (string) ($verify['message'] ?? 'Token invalid'));
            $results[] = [
                'bot_id'  => $botId,
                'ok'      => false,
                'action'  => 'marked_disconnected',
                'message' => (string) ($verify['message'] ?? 'Token invalid'),
            ];
            continue;
        }

        $appMismatch = whatsapp_check_token_app_match($plain);
        if ($appMismatch !== null) {
            whatsapp_mark_token_failure($botId, $appMismatch['message']);
            $results[] = [
                'bot_id'  => $botId,
                'ok'      => false,
                'action'  => 'app_mismatch',
                'message' => $appMismatch['message'],
            ];
            continue;
        }

        $inspect = whatsapp_inspect_token($plain);
        $expiresAt = null;
        $expiresUnix = (int) ($inspect['expires_at'] ?? 0);
        if ($expiresUnix > 0) {
            $expiresAt = date('Y-m-d H:i:s', $expiresUnix);
        }

        db_execute(
            'UPDATE bots SET whatsapp_verified = 1, whatsapp_token_checked_at = NOW(), whatsapp_token_error = NULL,
                whatsapp_token_app_id = ?
             WHERE id = ?',
            'si',
            [(string) ($inspect['app_id'] ?? ''), $botId]
        );
        if ($expiresAt !== null) {
            db_execute('UPDATE bots SET whatsapp_token_expires_at = ? WHERE id = ?', 'si', [$expiresAt, $botId]);
        }

        $results[] = [
            'bot_id'  => $botId,
            'ok'      => true,
            'action'  => 'healthy',
            'message' => 'Token OK',
        ];

        if ($expiresUnix > 0 && $expiresUnix - time() <= 7 * 86400) {
            $daysLeft = max(0, (int) floor(($expiresUnix - time()) / 86400));
            if (function_exists('send_email') && defined('ADMIN_EMAIL') && ADMIN_EMAIL !== '') {
                send_email(
                    ADMIN_EMAIL,
                    'WhatsApp token expiring soon — bot #' . $botId,
                    email_template(
                        'WhatsApp token expiring',
                        '<p>Bot "' . htmlspecialchars((string) ($bot['name'] ?? ''), ENT_QUOTES, 'UTF-8') . '" expires in '
                        . $daysLeft . ' day(s).</p><p>Client: ' . htmlspecialchars((string) ($bot['client_email'] ?? ''), ENT_QUOTES, 'UTF-8')
                        . '</p><p>Ask them to reconnect with a permanent System User token.</p>',
                        APP_URL . '/admin/dashboard',
                        'Admin dashboard'
                    )
                );
            }
        }
    }

    return $results;
}

/**
 * Rate limit DeepSeek calls per bot (Meta recommends pacing outbound activity).
 */
function whatsapp_ai_rate_limit_ok(int $botId): bool
{
    ensure_whatsapp_token_schema();

    $bucket = date('Y-m-d H:i');
    db_execute(
        'INSERT INTO whatsapp_ai_rate (bot_id, minute_bucket, call_count) VALUES (?, ?, 1)
         ON DUPLICATE KEY UPDATE call_count = call_count + 1',
        'is',
        [$botId, $bucket]
    );

    $row = db_fetch(
        'SELECT call_count FROM whatsapp_ai_rate WHERE bot_id = ? AND minute_bucket = ?',
        'is',
        [$botId, $bucket]
    );

    return (int) ($row['call_count'] ?? 0) <= 50;
}

function whatsapp_ai_rate_limit_message(): string
{
    return 'Thanks for your message! We\'re handling a high volume right now — someone will reply shortly.';
}
