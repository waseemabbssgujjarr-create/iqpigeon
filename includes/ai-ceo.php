<?php
/**
 * AI CEO / Growth Engine integration — multi-tenant.
 *
 * Each paid iqpigeon client connects their own crawler instance via the
 * client portal. Platform admins get an oversight page. No Nest/PHP mix:
 * contracts are HTTP only (ingest + outcome callbacks).
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

function ai_ceo_ensure_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $conn = db_connect();

    $conn->query(
        'CREATE TABLE IF NOT EXISTS ai_ceo_connections (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            bot_id INT UNSIGNED NOT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 0,
            api_key VARCHAR(64) NOT NULL,
            webhook_secret_enc TEXT NOT NULL,
            growth_engine_base_url VARCHAR(512) NULL,
            require_no_website TINYINT(1) NOT NULL DEFAULT 1,
            min_priority INT NOT NULL DEFAULT 60,
            outreach_per_run INT NOT NULL DEFAULT 1,
            outreach_delay_sec INT NOT NULL DEFAULT 90,
            outreach_template VARCHAR(128) NULL,
            last_error TEXT NULL,
            last_ingest_at DATETIME NULL,
            leads_received INT UNSIGNED NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_ai_ceo_api_key (api_key),
            UNIQUE KEY uq_ai_ceo_user (user_id),
            INDEX idx_ai_ceo_bot (bot_id),
            INDEX idx_ai_ceo_enabled (enabled)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $conn->query(
        'CREATE TABLE IF NOT EXISTS ai_ceo_outreach_queue (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            connection_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            bot_id INT UNSIGNED NOT NULL,
            lead_id INT UNSIGNED NOT NULL,
            business_id CHAR(36) NULL,
            landing_url VARCHAR(512) NULL,
            status ENUM(\'pending\',\'sent\',\'failed\',\'skipped\') NOT NULL DEFAULT \'pending\',
            attempts INT UNSIGNED NOT NULL DEFAULT 0,
            next_attempt_at DATETIME NOT NULL,
            last_error TEXT NULL,
            sent_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_ai_ceo_oq_due (status, next_attempt_at),
            INDEX idx_ai_ceo_oq_lead (lead_id),
            INDEX idx_ai_ceo_oq_conn (connection_id),
            UNIQUE KEY uq_ai_ceo_oq_lead (lead_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $conn->query(
        'CREATE TABLE IF NOT EXISTS ai_ceo_outcome_log (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            connection_id INT UNSIGNED NOT NULL,
            lead_id INT UNSIGNED NOT NULL,
            business_id CHAR(36) NOT NULL,
            status VARCHAR(64) NOT NULL,
            http_code INT NULL,
            response_body TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_ai_ceo_out_lead (lead_id),
            INDEX idx_ai_ceo_out_biz (business_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $done = true;
}

/**
 * Paid / trial clients may connect. Mirrors WhatsApp billing gate.
 */
function ai_ceo_user_allowed(array $user): bool
{
    require_once __DIR__ . '/billing-settings.php';
    return !billing_whatsapp_blocked_for_user($user);
}

/** Server client pages use this name (int user id or user row). */
function ai_ceo_user_has_access(int|array $userOrId): bool
{
    if (is_array($userOrId)) {
        return ai_ceo_user_allowed($userOrId);
    }

    if ($userOrId <= 0) {
        return false;
    }

    $user = db_fetch('SELECT * FROM users WHERE id = ?', 'i', [$userOrId]);

    return is_array($user) && ai_ceo_user_allowed($user);
}

function ai_ceo_generate_api_key(): string
{
    return 'iqp_ace_' . bin2hex(random_bytes(24));
}

function ai_ceo_generate_webhook_secret(): string
{
    return bin2hex(random_bytes(32));
}

/**
 * @return array<string, mixed>|null
 */
function ai_ceo_connection_for_user(int $userId): ?array
{
    ai_ceo_ensure_schema();
    return db_fetch('SELECT * FROM ai_ceo_connections WHERE user_id = ? LIMIT 1', 'i', [$userId]);
}

/**
 * @return array<string, mixed>|null
 */
function ai_ceo_connection_by_api_key(string $apiKey): ?array
{
    ai_ceo_ensure_schema();
    $apiKey = trim($apiKey);
    if ($apiKey === '') {
        return null;
    }
    return db_fetch('SELECT * FROM ai_ceo_connections WHERE api_key = ? LIMIT 1', 's', [$apiKey]);
}

function ai_ceo_plain_secret(array $connection): string
{
    $enc = (string) ($connection['webhook_secret_enc'] ?? '');
    if ($enc === '') {
        return '';
    }
    $plain = decrypt_token($enc);
    return is_string($plain) ? $plain : '';
}

/**
 * Create or update the single connection row for a client account.
 *
 * @param array<string, mixed> $input
 * @return array{connection: array<string, mixed>, rotated_key?: string, rotated_secret?: string}
 */
function ai_ceo_save_connection(int $userId, array $input): array
{
    ai_ceo_ensure_schema();

    $botId = (int) ($input['bot_id'] ?? 0);
    $owned = db_fetch('SELECT id FROM bots WHERE id = ? AND user_id = ?', 'ii', [$botId, $userId]);
    if (!$owned) {
        throw new InvalidArgumentException('Select a bot you own.');
    }

    $existing = ai_ceo_connection_for_user($userId);
    $enabled = !empty($input['enabled']) ? 1 : 0;
    $requireNoWebsite = !empty($input['require_no_website']) ? 1 : 0;
    $minPriority = max(0, min(100, (int) ($input['min_priority'] ?? 60)));
    $outreachPerRun = max(1, min(20, (int) ($input['outreach_per_run'] ?? 1)));
    $outreachDelay = max(30, min(3600, (int) ($input['outreach_delay_sec'] ?? 90)));
    $template = trim((string) ($input['outreach_template'] ?? ''));
    $baseUrl = rtrim(trim((string) ($input['growth_engine_base_url'] ?? '')), '/');

    $rotatedKey = null;
    $rotatedSecret = null;

    if (!$existing) {
        $apiKey = ai_ceo_generate_api_key();
        $secret = ai_ceo_generate_webhook_secret();
        $rotatedKey = $apiKey;
        $rotatedSecret = $secret;
        $id = db_insert(
            'INSERT INTO ai_ceo_connections
              (user_id, bot_id, enabled, api_key, webhook_secret_enc, growth_engine_base_url,
               require_no_website, min_priority, outreach_per_run, outreach_delay_sec, outreach_template)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            'iiisssiiiis',
            [
                $userId,
                $botId,
                $enabled,
                $apiKey,
                encrypt_token($secret),
                $baseUrl !== '' ? $baseUrl : null,
                $requireNoWebsite,
                $minPriority,
                $outreachPerRun,
                $outreachDelay,
                $template !== '' ? $template : null,
            ]
        );
        $connection = db_fetch('SELECT * FROM ai_ceo_connections WHERE id = ?', 'i', [$id]);
    } else {
        $apiKey = (string) $existing['api_key'];
        $secretEnc = (string) $existing['webhook_secret_enc'];

        if (!empty($input['rotate_api_key'])) {
            $apiKey = ai_ceo_generate_api_key();
            $rotatedKey = $apiKey;
        }
        if (!empty($input['rotate_secret'])) {
            $rotatedSecret = ai_ceo_generate_webhook_secret();
            $secretEnc = encrypt_token($rotatedSecret);
        }

        db_execute(
            'UPDATE ai_ceo_connections SET
                bot_id = ?, enabled = ?, api_key = ?, webhook_secret_enc = ?,
                growth_engine_base_url = ?, require_no_website = ?, min_priority = ?,
                outreach_per_run = ?, outreach_delay_sec = ?, outreach_template = ?,
                last_error = NULL
             WHERE user_id = ?',
            'iisssiiiisi',
            [
                $botId,
                $enabled,
                $apiKey,
                $secretEnc,
                $baseUrl !== '' ? $baseUrl : null,
                $requireNoWebsite,
                $minPriority,
                $outreachPerRun,
                $outreachDelay,
                $template !== '' ? $template : null,
                $userId,
            ]
        );
        $connection = ai_ceo_connection_for_user($userId);
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

function ai_ceo_phone_digits(string $phoneE164): string
{
    return preg_replace('/\D/', '', $phoneE164) ?? '';
}

/**
 * Verify inbound Growth Engine request.
 *
 * @return array{ok: bool, error?: string, connection?: array<string, mixed>, raw?: string, body?: array<string, mixed>}
 */
function ai_ceo_verify_ingest_request(): array
{
    ai_ceo_ensure_schema();

    $raw = file_get_contents('php://input');
    if ($raw === false) {
        $raw = '';
    }

    // Apache/CGI/cPanel often strips Authorization; recover from common fallbacks.
    $auth = (string) ($_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? '');
    if ($auth === '' && function_exists('getallheaders')) {
        $headers = getallheaders();
        if (is_array($headers)) {
            foreach ($headers as $name => $value) {
                if (strcasecmp((string) $name, 'Authorization') === 0) {
                    $auth = (string) $value;
                    break;
                }
            }
        }
    }
    if (!preg_match('/^Bearer\s+(\S+)/i', $auth, $m)) {
        return ['ok' => false, 'error' => 'Missing Bearer API key'];
    }
    $connection = ai_ceo_connection_by_api_key($m[1]);
    if (!$connection) {
        return ['ok' => false, 'error' => 'Invalid API key'];
    }
    if (empty($connection['enabled'])) {
        return ['ok' => false, 'error' => 'Integration disabled'];
    }

    $user = db_fetch(
        'SELECT id, subscription_status, trial_ends_at FROM users WHERE id = ?',
        'i',
        [(int) $connection['user_id']]
    );
    if (!$user || !ai_ceo_user_allowed($user)) {
        return ['ok' => false, 'error' => 'Subscription inactive — upgrade to use AI CEO'];
    }

    $ts = (string) ($_SERVER['HTTP_X_IQP_TIMESTAMP'] ?? '');
    $sig = (string) ($_SERVER['HTTP_X_IQP_SIGNATURE'] ?? '');
    if ($ts === '' || $sig === '') {
        return ['ok' => false, 'error' => 'Missing signature headers'];
    }
    if (!ctype_digit($ts) || abs(time() - (int) $ts) > 300) {
        return ['ok' => false, 'error' => 'Timestamp skew too large'];
    }

    $secret = ai_ceo_plain_secret($connection);
    if ($secret === '') {
        return ['ok' => false, 'error' => 'Connection secret misconfigured'];
    }
    $expected = hash_hmac('sha256', $ts . '.' . $raw, $secret);
    if (!hash_equals($expected, strtolower($sig)) && !hash_equals($expected, $sig)) {
        return ['ok' => false, 'error' => 'Invalid signature'];
    }

    $body = json_decode($raw, true);
    if (!is_array($body)) {
        return ['ok' => false, 'error' => 'Invalid JSON body'];
    }

    return ['ok' => true, 'connection' => $connection, 'raw' => $raw, 'body' => $body];
}

/**
 * Ingest one lead DTO from Growth Engine.
 *
 * @param array<string, mixed> $connection
 * @param array<string, mixed> $dto
 * @return array{leadId: int, created: bool, queued: bool}
 */
function ai_ceo_ingest_lead(array $connection, array $dto): array
{
    ai_ceo_ensure_schema();

    $phone = ai_ceo_phone_digits((string) ($dto['phoneE164'] ?? $dto['phone'] ?? ''));
    if (strlen($phone) < 8) {
        throw new InvalidArgumentException('phoneE164 is required');
    }

    $name = trim((string) ($dto['canonicalName'] ?? $dto['businessName'] ?? 'Business'));
    if ($name === '') {
        $name = 'Business';
    }

    $businessId = trim((string) ($dto['businessId'] ?? $dto['idempotencyKey'] ?? ''));
    $priority = (int) ($dto['priorityScore'] ?? 0);
    $minPriority = (int) ($connection['min_priority'] ?? 60);
    if ($priority > 0 && $priority < $minPriority) {
        throw new InvalidArgumentException('priorityScore below connection minimum');
    }

    $hasWebsite = null;
    if (array_key_exists('hasWebsite', $dto)) {
        $hasWebsite = (bool) $dto['hasWebsite'];
    }
    if (!empty($connection['require_no_website']) && $hasWebsite === true) {
        throw new InvalidArgumentException('Connection requires hasWebsite=false');
    }

    $botId = (int) $connection['bot_id'];
    $userId = (int) $connection['user_id'];
    $landingUrl = trim((string) ($dto['landingUrl'] ?? ''));

    $meta = [
        'source'            => 'ai_ceo',
        'business_id'       => $businessId !== '' ? $businessId : null,
        'landing_url'       => $landingUrl !== '' ? $landingUrl : null,
        'landing_token'     => $dto['landingToken'] ?? null,
        'priority_score'    => $priority,
        'suggested_service' => $dto['suggestedService'] ?? null,
        'suggested_opening' => $dto['suggestedOpening'] ?? null,
        'city'              => $dto['city'] ?? null,
        'country_code'      => $dto['countryCode'] ?? null,
        'category'          => $dto['category'] ?? null,
        'has_website'       => $hasWebsite,
        'website_url'       => $dto['websiteUrl'] ?? null,
        'maps_url'          => $dto['mapsUrl'] ?? null,
        'exported_at'       => $dto['exportedAt'] ?? date('c'),
        'schema_version'    => $dto['schemaVersion'] ?? 1,
    ];

    $existing = db_fetch(
        'SELECT id, qualification_data, status FROM leads WHERE bot_id = ? AND external_id = ? LIMIT 1',
        'is',
        [$botId, $phone]
    );

    $created = false;
    if ($existing) {
        $prev = [];
        if (!empty($existing['qualification_data'])) {
            $decoded = json_decode((string) $existing['qualification_data'], true);
            if (is_array($decoded)) {
                $prev = $decoded;
            }
        }
        $merged = array_merge($prev, $meta);
        db_execute(
            'UPDATE leads SET name = ?, score = GREATEST(score, ?), qualification_data = ?, updated_at = NOW() WHERE id = ?',
            'sisi',
            [$name, max($priority, 1), json_encode($merged, JSON_UNESCAPED_UNICODE), (int) $existing['id']]
        );
        $leadId = (int) $existing['id'];
    } else {
        $leadId = db_insert(
            'INSERT INTO leads (bot_id, external_id, name, platform, status, qualification_data, score, notes)
             VALUES (?, ?, ?, \'whatsapp\', \'new\', ?, ?, ?)',
            'isssis',
            [
                $botId,
                $phone,
                $name,
                json_encode($meta, JSON_UNESCAPED_UNICODE),
                max($priority, 1),
                'Imported from AI CEO crawler',
            ]
        );
        $created = true;
    }

    $queued = ai_ceo_enqueue_outreach(
        (int) $connection['id'],
        $userId,
        $botId,
        $leadId,
        $businessId !== '' ? $businessId : null,
        $landingUrl !== '' ? $landingUrl : null
    );

    db_execute(
        'UPDATE ai_ceo_connections SET last_ingest_at = NOW(), leads_received = leads_received + 1, last_error = NULL WHERE id = ?',
        'i',
        [(int) $connection['id']]
    );

    return ['leadId' => $leadId, 'created' => $created, 'queued' => $queued];
}

function ai_ceo_enqueue_outreach(
    int $connectionId,
    int $userId,
    int $botId,
    int $leadId,
    ?string $businessId,
    ?string $landingUrl
): bool {
    ai_ceo_ensure_schema();
    $existing = db_fetch(
        'SELECT id, status FROM ai_ceo_outreach_queue WHERE lead_id = ? LIMIT 1',
        'i',
        [$leadId]
    );
    if ($existing) {
        return false;
    }

    db_insert(
        'INSERT INTO ai_ceo_outreach_queue
          (connection_id, user_id, bot_id, lead_id, business_id, landing_url, status, next_attempt_at)
         VALUES (?, ?, ?, ?, ?, ?, \'pending\', NOW())',
        'iiiiss',
        [$connectionId, $userId, $botId, $leadId, $businessId, $landingUrl]
    );
    return true;
}

/**
 * Build intro text for cold outreach.
 *
 * @param array<string, mixed> $lead
 * @param array<string, mixed> $meta
 */
function ai_ceo_outreach_message(array $lead, array $meta): string
{
    $name = trim((string) ($lead['name'] ?? 'there'));
    $opening = trim((string) ($meta['suggested_opening'] ?? ''));
    $landing = trim((string) ($meta['landing_url'] ?? ''));
    $service = trim((string) ($meta['suggested_service'] ?? ''));

    $lines = [];
    $lines[] = 'Hi' . ($name !== '' && $name !== 'Business' ? ' ' . $name : '') . ' 👋';
    if ($opening !== '') {
        $lines[] = $opening;
    } else {
        $lines[] = "I noticed your business doesn't have a strong online presence yet — we help local businesses get a website and grow leads.";
    }
    if ($service !== '') {
        $lines[] = 'Something that often fits: ' . str_replace('_', ' ', $service) . '.';
    }
    if ($landing !== '') {
        $lines[] = 'Here is a short page tailored for you: ' . $landing;
    }
    $lines[] = 'Happy to hop on a quick call if useful — reply here anytime.';

    return implode("\n\n", $lines);
}

/**
 * Process due outreach rows across all enabled connections (cron).
 *
 * @return array{sent: int, failed: int, skipped: int}
 */
function ai_ceo_process_outreach_all(): array
{
    ai_ceo_ensure_schema();
    require_once __DIR__ . '/whatsapp.php';

    $stats = ['sent' => 0, 'failed' => 0, 'skipped' => 0];
    $connections = db_fetch_all(
        'SELECT * FROM ai_ceo_connections WHERE enabled = 1 ORDER BY id ASC'
    );

    foreach ($connections as $connection) {
        $user = db_fetch(
            'SELECT id, subscription_status, trial_ends_at FROM users WHERE id = ?',
            'i',
            [(int) $connection['user_id']]
        );
        if (!$user || !ai_ceo_user_allowed($user)) {
            continue;
        }

        $limit = max(1, (int) $connection['outreach_per_run']);
        $delay = max(30, (int) $connection['outreach_delay_sec']);
        $creds = whatsapp_bot_credentials((int) $connection['bot_id'], (int) $connection['user_id']);
        if (!$creds) {
            db_execute(
                'UPDATE ai_ceo_connections SET last_error = ? WHERE id = ?',
                'si',
                ['WhatsApp not connected on target bot', (int) $connection['id']]
            );
            continue;
        }

        $rows = db_fetch_all(
            'SELECT q.* FROM ai_ceo_outreach_queue q
             WHERE q.connection_id = ? AND q.status = \'pending\' AND q.next_attempt_at <= NOW()
             ORDER BY q.id ASC LIMIT ?',
            'ii',
            [(int) $connection['id'], $limit]
        );

        foreach ($rows as $row) {
            $lead = db_fetch('SELECT * FROM leads WHERE id = ?', 'i', [(int) $row['lead_id']]);
            if (!$lead) {
                db_execute(
                    'UPDATE ai_ceo_outreach_queue SET status = \'skipped\', last_error = ? WHERE id = ?',
                    'si',
                    ['Lead missing', (int) $row['id']]
                );
                $stats['skipped']++;
                continue;
            }

            $meta = [];
            if (!empty($lead['qualification_data'])) {
                $decoded = json_decode((string) $lead['qualification_data'], true);
                if (is_array($decoded)) {
                    $meta = $decoded;
                }
            }
            if (!empty($row['landing_url'])) {
                $meta['landing_url'] = $row['landing_url'];
            }

            $phone = (string) ($lead['external_id'] ?? '');
            $text = ai_ceo_outreach_message($lead, $meta);
            $template = trim((string) ($connection['outreach_template'] ?? ''));

            if ($template !== '') {
                $result = send_whatsapp_template(
                    $creds['phone_id'],
                    $creds['token'],
                    $phone,
                    $template,
                    'en',
                    array_values(array_filter([
                        (string) ($lead['name'] ?? ''),
                        (string) ($meta['landing_url'] ?? ''),
                    ], static fn ($v) => $v !== ''))
                );
            } else {
                $result = send_whatsapp_message_human(
                    $creds['phone_id'],
                    $creds['token'],
                    $phone,
                    $text
                );
            }

            $attempts = (int) $row['attempts'] + 1;
            if (!empty($result['success'])) {
                db_execute(
                    'UPDATE ai_ceo_outreach_queue SET status = \'sent\', attempts = ?, sent_at = NOW(), last_error = NULL WHERE id = ?',
                    'ii',
                    [$attempts, (int) $row['id']]
                );
                db_execute(
                    'UPDATE leads SET status = IF(status = \'new\', \'in_progress\', status), updated_at = NOW() WHERE id = ?',
                    'i',
                    [(int) $lead['id']]
                );
                $stats['sent']++;
            } else {
                $err = (string) ($result['message'] ?? 'Send failed');
                $status = $attempts >= 5 ? 'failed' : 'pending';
                $next = date('Y-m-d H:i:s', time() + $delay * $attempts);
                db_execute(
                    'UPDATE ai_ceo_outreach_queue SET status = ?, attempts = ?, next_attempt_at = ?, last_error = ? WHERE id = ?',
                    'siisi',
                    [$status, $attempts, $next, $err, (int) $row['id']]
                );
                db_execute(
                    'UPDATE ai_ceo_connections SET last_error = ? WHERE id = ?',
                    'si',
                    [$err, (int) $connection['id']]
                );
                $stats['failed']++;
            }

            // Pace within the same cron tick.
            if ($delay > 0 && count($rows) > 1) {
                usleep(min($delay, 15) * 100000);
            }
        }
    }

    return $stats;
}

/**
 * Push lead status changes back to the tenant's Growth Engine.
 */
function ai_ceo_notify_outcome(int $leadId, string $status): void
{
    ai_ceo_ensure_schema();

    $lead = db_fetch('SELECT * FROM leads WHERE id = ?', 'i', [$leadId]);
    if (!$lead) {
        return;
    }

    $meta = [];
    if (!empty($lead['qualification_data'])) {
        $decoded = json_decode((string) $lead['qualification_data'], true);
        if (is_array($decoded)) {
            $meta = $decoded;
        }
    }
    if (($meta['source'] ?? '') !== 'ai_ceo') {
        return;
    }
    $businessId = trim((string) ($meta['business_id'] ?? ''));
    if ($businessId === '') {
        return;
    }

    $bot = db_fetch('SELECT user_id FROM bots WHERE id = ?', 'i', [(int) $lead['bot_id']]);
    if (!$bot) {
        return;
    }
    $connection = ai_ceo_connection_for_user((int) $bot['user_id']);
    if (!$connection || empty($connection['enabled'])) {
        return;
    }
    $base = rtrim((string) ($connection['growth_engine_base_url'] ?? ''), '/');
    if ($base === '') {
        return;
    }

    $secret = ai_ceo_plain_secret($connection);
    $occurredAt = date('c');
    $statusNorm = $status;
    $canonical = $businessId . '|' . $statusNorm . '|' . $occurredAt . '|' . $leadId;
    $payloadArr = [
        'schemaVersion'  => 1,
        'businessId'     => $businessId,
        'iqpigeonLeadId' => $leadId,
        'status'         => $statusNorm,
        'occurredAt'     => $occurredAt,
        'score'          => (int) ($lead['score'] ?? 0),
    ];
    $payload = json_encode($payloadArr, JSON_UNESCAPED_UNICODE);
    if ($payload === false) {
        return;
    }

    $ts = (string) time();
    $sig = hash_hmac('sha256', $ts . '.' . $canonical, $secret);
    $url = $base . '/api/v1/webhooks/iqpigeon/outcomes';

    $ch = curl_init($url);
    if ($ch === false) {
        return;
    }
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . (string) $connection['api_key'],
            'X-IQP-Timestamp: ' . $ts,
            'X-IQP-Signature: ' . $sig,
        ],
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    db_insert(
        'INSERT INTO ai_ceo_outcome_log (connection_id, lead_id, business_id, status, http_code, response_body)
         VALUES (?, ?, ?, ?, ?, ?)',
        'iissis',
        [
            (int) $connection['id'],
            $leadId,
            $businessId,
            $status,
            $code,
            is_string($body) ? substr($body, 0, 2000) : null,
        ]
    );
}

/**
 * @return array{pending: int, sent: int, failed: int, received_today: int}
 */
function ai_ceo_connection_stats(int $connectionId): array
{
    ai_ceo_ensure_schema();
    $pending = db_fetch(
        'SELECT COUNT(*) AS c FROM ai_ceo_outreach_queue WHERE connection_id = ? AND status = \'pending\'',
        'i',
        [$connectionId]
    );
    $sent = db_fetch(
        'SELECT COUNT(*) AS c FROM ai_ceo_outreach_queue WHERE connection_id = ? AND status = \'sent\'',
        'i',
        [$connectionId]
    );
    $failed = db_fetch(
        'SELECT COUNT(*) AS c FROM ai_ceo_outreach_queue WHERE connection_id = ? AND status = \'failed\'',
        'i',
        [$connectionId]
    );
    $today = db_fetch(
        'SELECT COUNT(*) AS c FROM ai_ceo_outreach_queue WHERE connection_id = ? AND DATE(created_at) = CURDATE()',
        'i',
        [$connectionId]
    );

    return [
        'pending'        => (int) ($pending['c'] ?? 0),
        'sent'           => (int) ($sent['c'] ?? 0),
        'failed'         => (int) ($failed['c'] ?? 0),
        'received_today' => (int) ($today['c'] ?? 0),
    ];
}

/**
 * Admin oversight list.
 *
 * @return list<array<string, mixed>>
 */
function ai_ceo_admin_list_connections(): array
{
    ai_ceo_ensure_schema();
    return db_fetch_all(
        'SELECT c.*, u.name AS user_name, u.email AS user_email, u.company_name,
                u.subscription_status, b.name AS bot_name
         FROM ai_ceo_connections c
         JOIN users u ON u.id = c.user_id
         LEFT JOIN bots b ON b.id = c.bot_id
         ORDER BY c.updated_at DESC'
    );
}

function ai_ceo_ingest_url(): string
{
    $base = defined('APP_URL') ? rtrim((string) APP_URL, '/') : '';
    return $base . '/api/ai-ceo-leads.php';
}
