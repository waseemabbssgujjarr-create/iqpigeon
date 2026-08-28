<?php
/**
 * Firebase Cloud Messaging — push alerts when app is closed.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

function push_notifications_enabled(): bool
{
    return defined('FCM_PROJECT_ID') && FCM_PROJECT_ID !== ''
        && defined('FCM_SERVICE_ACCOUNT_PATH') && FCM_SERVICE_ACCOUNT_PATH !== ''
        && is_readable(FCM_SERVICE_ACCOUNT_PATH);
}

function push_tokens_table_ready(): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }

    if (!function_exists('db_table_exists')) {
        require_once __DIR__ . '/auth.php';
    }

    $ready = db_table_exists('device_push_tokens');
    return $ready;
}

/**
 * Save or refresh a device FCM token for the logged-in user.
 */
function push_register_device_token(int $userId, string $token, string $platform = 'android', string $appVersion = ''): bool
{
    if (!push_tokens_table_ready() || $token === '') {
        return false;
    }

    $platform = in_array($platform, ['android', 'ios', 'web'], true) ? $platform : 'android';
    $token = substr($token, 0, 512);

    $existing = db_fetch(
        'SELECT id FROM device_push_tokens WHERE user_id = ? AND token = ?',
        'is',
        [$userId, $token]
    );

    if ($existing) {
        db_execute(
            'UPDATE device_push_tokens SET last_seen_at = NOW(), app_version = ?, platform = ? WHERE id = ?',
            'ssi',
            [$appVersion, $platform, (int) $existing['id']]
        );
        return true;
    }

    db_insert(
        'INSERT INTO device_push_tokens (user_id, token, platform, app_version, last_seen_at) VALUES (?, ?, ?, ?, NOW())',
        'isss',
        [$userId, $token, $platform, $appVersion]
    );

    return true;
}

/**
 * @return array<int, string>
 */
function push_get_user_tokens(int $userId): array
{
    if (!push_tokens_table_ready()) {
        return [];
    }

    $rows = db_fetch_all(
        'SELECT token FROM device_push_tokens WHERE user_id = ? ORDER BY last_seen_at DESC LIMIT 20',
        'i',
        [$userId]
    );

    return array_values(array_filter(array_map(static fn ($r) => (string) ($r['token'] ?? ''), $rows)));
}

/**
 * Send push notification to all devices for a user.
 */
function push_notify_user(int $userId, string $title, string $body, ?string $link = null): int
{
    if (!push_notifications_enabled()) {
        return 0;
    }

    $tokens = push_get_user_tokens($userId);
    if ($tokens === []) {
        return 0;
    }

    $sent = 0;
    foreach ($tokens as $token) {
        if (push_send_fcm($token, $title, $body, $link)) {
            $sent++;
        }
    }

    return $sent;
}

function push_send_fcm(string $deviceToken, string $title, string $body, ?string $link = null): bool
{
    $accessToken = push_fcm_access_token();
    if (!$accessToken) {
        return false;
    }

    $projectId = FCM_PROJECT_ID;
    $payload = [
        'message' => [
            'token' => $deviceToken,
            'notification' => [
                'title' => $title,
                'body'  => $body,
            ],
            'android' => [
                'priority'     => 'HIGH',
                'notification' => [
                    'channel_id' => 'leads_alerts',
                    'icon'       => 'ic_launcher',
                    'color'      => '#4aad36',
                ],
            ],
            'data' => [
                'link' => $link ?? '',
            ],
        ],
    ];

    $url = 'https://fcm.googleapis.com/v1/projects/' . rawurlencode($projectId) . '/messages:send';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
    ]);

    if (defined('OPENAI_SSL_VERIFY') && !OPENAI_SSL_VERIFY) {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    }

    $response = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code >= 200 && $code < 300) {
        return true;
    }

    error_log('FCM send failed HTTP ' . $code . ': ' . (string) $response);
    return false;
}

function push_fcm_access_token(): ?string
{
    static $cached = null;
    static $expires = 0;

    if ($cached && time() < $expires - 60) {
        return $cached;
    }

    $path = FCM_SERVICE_ACCOUNT_PATH;
    if (!is_readable($path)) {
        return null;
    }

    $sa = json_decode((string) file_get_contents($path), true);
    if (!is_array($sa) || empty($sa['client_email']) || empty($sa['private_key'])) {
        return null;
    }

    $now = time();
    $header = push_base64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $claim = push_base64url(json_encode([
        'iss'   => $sa['client_email'],
        'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        'aud'   => 'https://oauth2.googleapis.com/token',
        'iat'   => $now,
        'exp'   => $now + 3600,
    ]));

    $unsigned = $header . '.' . $claim;
    $signature = '';
    $key = openssl_pkey_get_private($sa['private_key']);
    if (!$key) {
        return null;
    }

    openssl_sign($unsigned, $signature, $key, OPENSSL_ALGO_SHA256);
    $jwt = $unsigned . '.' . push_base64url($signature);

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
    ]);

    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode((string) $response, true);
    if (empty($data['access_token'])) {
        error_log('FCM OAuth failed: ' . (string) $response);
        return null;
    }

    $cached = $data['access_token'];
    $expires = $now + (int) ($data['expires_in'] ?? 3600);

    return $cached;
}

function push_base64url(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}
