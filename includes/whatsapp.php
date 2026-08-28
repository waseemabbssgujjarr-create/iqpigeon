<?php
/**
 * WhatsApp Business Cloud API helpers.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/helpers.php';

function whatsapp_manual_mode(): bool
{
    require_once __DIR__ . '/integration-settings.php';

    return integration_whatsapp_manual_mode();
}

function whatsapp_meta_app_id(): string
{
    if (!function_exists('integration_meta_credentials')) {
        require_once __DIR__ . '/integration-settings.php';
    }

    if (function_exists('integration_meta_credentials')) {
        return integration_meta_credentials()['app_id'];
    }

    return defined('META_APP_ID') ? trim((string) META_APP_ID) : '';
}

function whatsapp_meta_app_secret(): string
{
    if (!function_exists('integration_meta_credentials')) {
        require_once __DIR__ . '/integration-settings.php';
    }

    if (function_exists('integration_meta_credentials')) {
        return integration_meta_credentials()['app_secret'];
    }

    return defined('META_APP_SECRET') ? trim((string) META_APP_SECRET) : '';
}

function whatsapp_graph_api_version(): string
{
    if (!function_exists('integration_meta_graph_api_version')) {
        require_once __DIR__ . '/integration-settings.php';
    }

    if (function_exists('integration_meta_graph_api_version')) {
        return integration_meta_graph_api_version();
    }

    $raw = defined('META_GRAPH_API_VERSION') ? trim((string) META_GRAPH_API_VERSION) : 'v25.0';
    if (preg_match('/^v\d+\.\d+$/', $raw)) {
        return $raw;
    }
    if (preg_match('/^\d+\.\d+$/', $raw)) {
        return 'v' . $raw;
    }

    return 'v25.0';
}

/**
 * Apply SSL verify options for Meta Graph curl calls (shared hosting).
 *
 * @param array<int, mixed> $opts
 * @return array<int, mixed>
 */
function whatsapp_curl_opts(array $opts): array
{
    if (!isset($opts[CURLOPT_CONNECTTIMEOUT])) {
        $opts[CURLOPT_CONNECTTIMEOUT] = 15;
    }
    if (defined('CURL_IPRESOLVE_V4') && !isset($opts[CURLOPT_IPRESOLVE])) {
        $opts[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4;
    }
    if (defined('META_GRAPH_SSL_VERIFY') && !META_GRAPH_SSL_VERIFY) {
        $opts[CURLOPT_SSL_VERIFYPEER] = false;
        $opts[CURLOPT_SSL_VERIFYHOST] = 0;
    }

    return $opts;
}

/**
 * Known Meta Graph CDN IPs (last-resort when host DNS is broken on shared hosting).
 *
 * @return list<string>
 */
function whatsapp_graph_static_ips(): array
{
    return [
        '157.240.241.174',
        '157.240.245.14',
        '31.13.64.35',
        '157.240.13.35',
    ];
}

/**
 * Resolve hostname to IPv4 — gethostbyname, dns_get_record, then static Graph IPs.
 */
function whatsapp_resolve_host_ip(string $host): ?string
{
    $host = trim($host);
    if ($host === '') {
        return null;
    }

    $ip = @gethostbyname($host);
    if ($ip !== $host && filter_var($ip, FILTER_VALIDATE_IP)) {
        return $ip;
    }

    if (function_exists('dns_get_record')) {
        $records = @dns_get_record($host, DNS_A);
        if (is_array($records)) {
            foreach ($records as $rec) {
                $candidate = (string) ($rec['ip'] ?? '');
                if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                    return $candidate;
                }
            }
        }
    }

    if ($host === 'graph.facebook.com') {
        return whatsapp_graph_static_ips()[0] ?? null;
    }

    if (in_array($host, ['oauth2.googleapis.com', 'www.googleapis.com', 'accounts.google.com'], true)) {
        return whatsapp_google_api_static_ips()[0] ?? null;
    }

    return null;
}

/**
 * Last-resort Google API IPs when host DNS is broken on shared hosting.
 *
 * @return list<string>
 */
function whatsapp_google_api_static_ips(): array
{
    return [
        '142.250.185.78',
        '172.217.164.110',
        '142.250.76.10',
    ];
}

/**
 * @return list<string|null> IPs to try via CURLOPT_RESOLVE (null = no override)
 */
function whatsapp_resolve_host_ip_candidates(string $host): array
{
    $candidates = [];
    $primary = whatsapp_resolve_host_ip($host);
    if ($primary !== null) {
        $candidates[] = $primary;
    }

    if ($host === 'graph.facebook.com') {
        foreach (whatsapp_graph_static_ips() as $staticIp) {
            if (!in_array($staticIp, $candidates, true)) {
                $candidates[] = $staticIp;
            }
        }
    }

    if (in_array($host, ['oauth2.googleapis.com', 'www.googleapis.com', 'accounts.google.com'], true)) {
        foreach (whatsapp_google_api_static_ips() as $staticIp) {
            if (!in_array($staticIp, $candidates, true)) {
                $candidates[] = $staticIp;
            }
        }
    }

    if ($candidates === []) {
        return [null];
    }

    return $candidates;
}

/**
 * @param array<int, mixed> $opts
 * @return array{ok: bool, body: string|false, http_code: int, curl_error: string}
 */
function whatsapp_curl_execute(string $url, array $opts): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, whatsapp_curl_opts(array_replace([
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
    ], $opts)));

    $body = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($body !== false) {
        return [
            'ok'         => true,
            'body'       => $body,
            'http_code'  => $httpCode,
            'curl_error' => '',
        ];
    }

    return [
        'ok'         => false,
        'body'       => false,
        'http_code'  => $httpCode,
        'curl_error' => $curlError,
    ];
}

/**
 * Shared curl wrapper for Meta Graph — surfaces DNS/network errors on shared hosting.
 *
 * @param array<int, mixed> $opts
 * @return array{ok: bool, body: string|false, http_code: int, curl_error: string}
 */
function whatsapp_curl_request(string $url, array $opts = []): array
{
    $host = parse_url($url, PHP_URL_HOST);
    $port = (int) (parse_url($url, PHP_URL_PORT) ?: 443);
    $ipCandidates = (is_string($host) && $host !== '' && defined('CURLOPT_RESOLVE'))
        ? whatsapp_resolve_host_ip_candidates($host)
        : [null];

    $lastResult = [
        'ok'         => false,
        'body'       => false,
        'http_code'  => 0,
        'curl_error' => '',
    ];

    foreach ($ipCandidates as $ip) {
        $attemptOpts = $opts;
        if ($ip !== null && is_string($host)) {
            $attemptOpts[CURLOPT_RESOLVE] = array_merge(
                (array) ($attemptOpts[CURLOPT_RESOLVE] ?? []),
                ["{$host}:{$port}:{$ip}"]
            );
        }

        $result = whatsapp_curl_execute($url, $attemptOpts);
        if ($result['ok']) {
            return $result;
        }

        $lastResult = $result;
        $err = $result['curl_error'];
        if (!str_contains($err, 'getaddrinfo') && !str_contains($err, 'Could not resolve')) {
            break;
        }
    }

    $curlError = $lastResult['curl_error'];
    if (str_contains($curlError, 'getaddrinfo') || str_contains($curlError, 'Could not resolve')) {
        $stream = whatsapp_stream_request($url, $opts, $curlError);
        if ($stream['ok']) {
            return $stream;
        }
    }

    return $lastResult;
}

/**
 * Fallback HTTP when libcurl threaded DNS fails on shared hosting.
 *
 * @param array<int, mixed> $curlOpts
 * @return array{ok: bool, body: string|false, http_code: int, curl_error: string}
 */
function whatsapp_stream_request(string $url, array $curlOpts, string $priorError = ''): array
{
    $method = !empty($curlOpts[CURLOPT_POST]) ? 'POST' : 'GET';
    $headers = [];
    foreach ((array) ($curlOpts[CURLOPT_HTTPHEADER] ?? []) as $line) {
        if (is_string($line) && $line !== '') {
            $headers[] = $line;
        }
    }

    $sslVerify = !(defined('META_GRAPH_SSL_VERIFY') && !META_GRAPH_SSL_VERIFY);
    $contextOpts = [
        'http' => [
            'method'        => $method,
            'header'        => implode("\r\n", $headers),
            'timeout'       => (float) ($curlOpts[CURLOPT_TIMEOUT] ?? 15),
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer'      => $sslVerify,
            'verify_peer_name' => $sslVerify,
        ],
    ];

    if ($method === 'POST' && isset($curlOpts[CURLOPT_POSTFIELDS])) {
        $contextOpts['http']['content'] = (string) $curlOpts[CURLOPT_POSTFIELDS];
    }

    $ctx = stream_context_create($contextOpts);
    $body = @file_get_contents($url, false, $ctx);
    $httpCode = 0;
    if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', (string) $http_response_header[0], $m)) {
        $httpCode = (int) $m[1];
    }

    if ($body !== false) {
        return [
            'ok'         => true,
            'body'       => $body,
            'http_code'  => $httpCode,
            'curl_error' => '',
        ];
    }

    $streamErr = error_get_last()['message'] ?? 'stream request failed';

    return [
        'ok'         => false,
        'body'       => false,
        'http_code'  => $httpCode,
        'curl_error' => $priorError !== '' ? $priorError : $streamErr,
    ];
}

function whatsapp_outbound_error_message(string $curlError, string $service = 'Meta Graph API'): string
{
    $curlError = trim($curlError);
    if ($curlError === '') {
        return 'Server cannot reach ' . $service . ' (outbound HTTPS blocked or DNS failure). Contact your hosting provider.';
    }

    return 'Server cannot reach ' . $service . ': ' . $curlError
        . '. Inbound webhooks still work; outbound replies need HTTPS to graph.facebook.com — ask your host to fix DNS/outbound curl.';
}

/**
 * Verify Meta webhook signature (X-Hub-Signature-256).
 *
 * @param string $payload Raw request body
 * @param string|null $signature Header value
 * @return bool
 */
function verify_meta_signature(string $payload, ?string $signature): bool
{
    $secret = whatsapp_meta_app_secret();
    if ($secret === '' || $secret === 'your_app_secret') {
        return false;
    }

    if (!$signature || !str_starts_with($signature, 'sha256=')) {
        return false;
    }

    $expected = 'sha256=' . hash_hmac('sha256', $payload, $secret);
    return hash_equals($expected, $signature);
}

/**
 * Send a WhatsApp text message via Cloud API.
 *
 * @param string $phoneId Phone Number ID
 * @param string $token Access token (decrypted)
 * @param string $to Recipient phone (E.164 without +)
 * @param string $text Message body
 * @return array{success: bool, message?: string}
 */
function send_whatsapp_message(string $phoneId, string $token, string $to, string $text): array
{
    $version = whatsapp_graph_api_version();
    $url = 'https://graph.facebook.com/' . $version . '/' . $phoneId . '/messages';

    $payload = json_encode([
        'messaging_product' => 'whatsapp',
        'to'                => preg_replace('/\D/', '', $to),
        'type'              => 'text',
        'text'              => ['body' => $text],
    ]);

    $result = whatsapp_curl_request($url, [
        CURLOPT_POST       => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
        ],
        CURLOPT_POSTFIELDS => $payload,
    ]);

    if (!$result['ok']) {
        $msg = whatsapp_outbound_error_message($result['curl_error']);
        error_log('WhatsApp send curl error: ' . $result['curl_error']);
        return ['success' => false, 'message' => $msg, 'http_code' => 0, 'curl_error' => $result['curl_error']];
    }

    $response = $result['body'];
    $httpCode = $result['http_code'];

    if ($httpCode >= 400) {
        $errBody = is_string($response) ? $response : '';
        error_log('WhatsApp send failed (' . $httpCode . '): ' . $errBody);
        $decoded = json_decode($errBody, true);
        $metaErr = $decoded['error']['message'] ?? 'Failed to send WhatsApp message.';
        return ['success' => false, 'message' => $metaErr, 'http_code' => $httpCode];
    }

    return ['success' => true];
}

/**
 * Mark an inbound WhatsApp message as read (shows blue ticks to customer).
 */
function whatsapp_mark_message_read(string $phoneId, string $token, string $messageId): bool
{
    $messageId = trim($messageId);
    if ($messageId === '') {
        return false;
    }

    $version = whatsapp_graph_api_version();
    $url = 'https://graph.facebook.com/' . $version . '/' . $phoneId . '/messages';

    $payload = json_encode([
        'messaging_product' => 'whatsapp',
        'status'            => 'read',
        'message_id'        => $messageId,
    ]);

    $result = whatsapp_curl_request($url, [
        CURLOPT_POST       => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
        ],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT    => 3,
    ]);

    $ok = $result['ok'] && $result['http_code'] < 400;
    $bodyPreview = is_string($result['body']) ? mb_substr($result['body'], 0, 240) : '';
    $log = [
        'ok'        => $ok,
        'http'      => (int) $result['http_code'],
        'curl'      => (string) ($result['curl_error'] ?? ''),
        'message_id'=> mb_substr($messageId, 0, 80),
        'body'      => $bodyPreview,
    ];
    error_log('WhatsApp mark read ' . json_encode($log, JSON_UNESCAPED_UNICODE));
    if (function_exists('whatsapp_webhook_log_event')) {
        whatsapp_webhook_log_event('Mark read', $log);
    }

    if (!$ok) {
        return false;
    }

    return true;
}

/**
 * Show typing indicator (best-effort; Meta Graph API v22+).
 */
function whatsapp_send_typing_indicator(string $phoneId, string $token, string $messageId): bool
{
    $messageId = trim($messageId);
    if ($messageId === '') {
        return false;
    }

    $version = whatsapp_graph_api_version();
    $url = 'https://graph.facebook.com/' . $version . '/' . $phoneId . '/messages';

    $payload = json_encode([
        'messaging_product' => 'whatsapp',
        'status'            => 'read',
        'message_id'        => $messageId,
        'typing_indicator'  => ['type' => 'text'],
    ]);

    $result = whatsapp_curl_request($url, [
        CURLOPT_POST       => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
        ],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT    => 8,
    ]);

    if (!$result['ok']) {
        error_log('WhatsApp typing indicator curl error: ' . $result['curl_error']);
        return false;
    }

    if ($result['http_code'] >= 400) {
        error_log('WhatsApp typing indicator failed (' . $result['http_code'] . '): ' . (is_string($result['body']) ? $result['body'] : ''));
        return false;
    }

    return true;
}

/**
 * Mark read immediately, then show typing if supported.
 */
function whatsapp_acknowledge_inbound(string $phoneId, string $token, string $messageId): void
{
    $messageId = trim($messageId);
    if ($messageId === '') {
        return;
    }

    // Mark read only — typing is shown when we commit to reply (process_turn), not on ingest.
    whatsapp_mark_message_read($phoneId, $token, $messageId);
}

function whatsapp_mark_read_and_typing(string $phoneId, string $token, string $messageId): bool
{
    whatsapp_acknowledge_inbound($phoneId, $token, $messageId);
    return true;
}

function whatsapp_refresh_typing_indicator(string $phoneId, string $token, string $messageId): bool
{
    return whatsapp_send_typing_indicator($phoneId, $token, $messageId);
}

/**
 * Show typing bubble for ~3–5s before a fast reply (greetings, fallbacks).
 */
function whatsapp_pre_reply_typing(string $phoneId, string $token, string $messageId, ?int $delayMs = null): void
{
    $delayMs = $delayMs ?? (defined('WHATSAPP_PRE_REPLY_TYPING_MS') ? (int) WHATSAPP_PRE_REPLY_TYPING_MS : 4000);
    $delayMs = max(1000, min(5000, $delayMs));

    if ($messageId === '') {
        usleep($delayMs * 1000);

        return;
    }

    whatsapp_human_delay_with_typing($phoneId, $token, $messageId, $delayMs, true);
}

/**
 * Pad with typing if the pipeline finished faster than the minimum visible typing window.
 */
function whatsapp_ensure_min_typing_elapsed(
    string $phoneId,
    string $token,
    string $messageId,
    float $pipelineStart,
    ?int $minMs = null,
    ?int $maxMs = null
): void {
    $minMs = $minMs ?? (defined('WHATSAPP_PRE_REPLY_TYPING_MS') ? (int) WHATSAPP_PRE_REPLY_TYPING_MS : 3000);
    $maxMs = $maxMs ?? 5000;
    $minMs = max(0, min($maxMs, $minMs));

    $elapsedMs = (int) round((microtime(true) - $pipelineStart) * 1000);
    $remaining = $minMs - $elapsedMs;

    if ($remaining <= 0) {
        return;
    }

    $remaining = min($remaining, $maxMs - min($elapsedMs, $maxMs));
    if ($remaining <= 0) {
        return;
    }

    if ($messageId === '') {
        usleep($remaining * 1000);

        return;
    }

    whatsapp_human_delay_with_typing($phoneId, $token, $messageId, $remaining, true);
}

function whatsapp_human_delay_with_typing(
    string $phoneId,
    string $token,
    string $messageId,
    int $delayMs,
    bool $alreadyRead = false
): void {
    $messageId = trim($messageId);
    $delayMs = max(0, $delayMs);
    if ($delayMs === 0) {
        return;
    }

    $pulseMs = defined('WHATSAPP_TYPING_PULSE_MS') ? (int) WHATSAPP_TYPING_PULSE_MS : 20000;
    $pulseMs = max(5000, min(24000, $pulseMs));

    if ($messageId !== '') {
        if (!$alreadyRead) {
            whatsapp_mark_message_read($phoneId, $token, $messageId);
        }
        whatsapp_send_typing_indicator($phoneId, $token, $messageId);
    }

    $remaining = $delayMs;
    while ($remaining > 0) {
        $chunk = min($remaining, $pulseMs);
        usleep($chunk * 1000);
        $remaining -= $chunk;

        if ($remaining > 0 && $messageId !== '') {
            whatsapp_send_typing_indicator($phoneId, $token, $messageId);
        }
    }
}

/**
 * Send WhatsApp reply with read receipt + human typing delay.
 *
 * @param array{incoming_text?: string, message_id?: string, already_read?: bool} $opts
 * @return array{success: bool, message?: string, delay_ms?: int}
 */
function send_whatsapp_message_human(string $phoneId, string $token, string $to, string $text, array $opts = []): array
{
    require_once __DIR__ . '/reply-timing.php';

    $incoming = $opts['incoming_text'] ?? null;
    $messageId = trim((string) ($opts['message_id'] ?? ''));
    $alreadyRead = !empty($opts['already_read']);

    $delayMs = human_agent_delay_ms($text, is_string($incoming) ? $incoming : null);

    if ($messageId !== '') {
        whatsapp_human_delay_with_typing($phoneId, $token, $messageId, $delayMs, $alreadyRead);
    } else {
        usleep($delayMs * 1000);
    }

    $result = send_whatsapp_message($phoneId, $token, $to, $text);
    $result['delay_ms'] = $delayMs;

    return $result;
}

/**
 * GET request to Meta Graph API.
 *
 * @return array{http_code: int, data: array<string, mixed>}
 */
function whatsapp_graph_get(string $path, string $token): array
{
    $version = whatsapp_graph_api_version();
    $url = 'https://graph.facebook.com/' . $version . '/' . ltrim($path, '/');

    $result = whatsapp_curl_request($url, [
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
    ]);

    $data = json_decode(is_string($result['body']) ? $result['body'] : '', true);
    if (!is_array($data)) {
        $data = [];
    }

    return [
        'http_code'  => $result['http_code'],
        'data'       => $data,
        'curl_error' => $result['curl_error'],
        'curl_ok'    => $result['ok'],
    ];
}

/**
 * Inspect token scopes (best-effort; requires app credentials).
 *
 * @return array{is_valid?: bool, has_whatsapp_scope?: bool, scopes?: string[]}
 */
function whatsapp_inspect_token(string $token): array
{
    if (!defined('META_APP_ID') || !defined('META_APP_SECRET') || META_APP_ID === '' || META_APP_SECRET === '') {
        return ['inspect_skipped' => true, 'reason' => 'META_APP_ID or META_APP_SECRET missing in config.php'];
    }

    $appToken = META_APP_ID . '|' . META_APP_SECRET;
    $url = 'https://graph.facebook.com/debug_token?input_token=' . urlencode($token)
        . '&access_token=' . urlencode($appToken);

    $result = whatsapp_curl_request($url, [CURLOPT_TIMEOUT => 10]);
    if (!$result['ok']) {
        return [
            'inspect_failed' => true,
            'reason'         => whatsapp_outbound_error_message($result['curl_error']),
        ];
    }

    $payload = json_decode(is_string($result['body']) ? $result['body'] : '', true);
    if (!empty($payload['error']['message'])) {
        return [
            'inspect_failed' => true,
            'reason'         => (string) $payload['error']['message'],
        ];
    }

    $info = $payload['data'] ?? [];
    if (empty($info['is_valid'])) {
        if ($result['http_code'] === 0) {
            return [
                'inspect_failed' => true,
                'reason'         => whatsapp_outbound_error_message($result['curl_error']),
            ];
        }
        return ['is_valid' => false];
    }

    $scopes = $info['scopes'] ?? [];
    $whatsappScopes = ['whatsapp_business_messaging', 'whatsapp_business_management'];
    $hasWhatsApp = (bool) array_intersect($whatsappScopes, $scopes);

    $wabaIds = [];
    foreach ($info['granular_scopes'] ?? [] as $scopeRow) {
        if (!is_array($scopeRow)) {
            continue;
        }
        $scopeName = $scopeRow['scope'] ?? '';
        if (str_starts_with($scopeName, 'whatsapp_business')) {
            foreach ($scopeRow['target_ids'] ?? [] as $id) {
                $wabaIds[] = (string) $id;
            }
        }
    }
    $wabaIds = array_values(array_unique($wabaIds));

    return [
        'is_valid'            => true,
        'has_whatsapp_scope'  => $hasWhatsApp,
        'has_catalog_scope'   => in_array('catalog_management', $scopes, true),
        'scopes'              => $scopes,
        'app_id'              => (string) ($info['app_id'] ?? ''),
        'waba_ids'            => $wabaIds,
        'expires_at'          => (int) ($info['expires_at'] ?? 0),
        'type'                => (string) ($info['type'] ?? ''),
    ];
}

/**
 * Turn Meta API errors into actionable setup guidance.
 */
function whatsapp_friendly_error(string $metaMessage, string $objectId): string
{
    $lower = strtolower($metaMessage);
    if (str_contains($lower, 'does not exist')
        || str_contains($lower, 'missing permissions')
        || str_contains($lower, 'unsupported get request')) {
        return 'Meta rejected this ID/token combination. Check: (1) Field 1 must be Phone number ID from WhatsApp → API Setup — not App ID or Business Account ID. (2) Token must include whatsapp_business_messaging permission. (3) In Business Settings → System users → Assign assets → add your WhatsApp account to the system user, then regenerate the token.';
    }

    if (str_contains($lower, 'expired') || str_contains($lower, 'session has expired')) {
        return 'Access token expired. Generate a new token in Meta (prefer System User token with Never expire).';
    }

    return $metaMessage;
}

/**
 * Verify WhatsApp credentials with a test API call.
 *
 * @param string $phoneId Phone Number ID (or WhatsApp Business Account ID — auto-resolved)
 * @param string $token
 * @return array{success: bool, message: string, phone_id?: string}
 */
function verify_whatsapp_credentials(string $phoneId, string $token): array
{
    $phoneId = trim($phoneId);

    // 0) Outbound network — misreported as "expired token" when curl/DNS fails
    $networkProbe = whatsapp_graph_get(
        rawurlencode($phoneId) . '?fields=id',
        $token
    );
    if (($networkProbe['curl_ok'] ?? true) === false || (($networkProbe['http_code'] ?? 0) === 0 && trim((string) ($networkProbe['curl_error'] ?? '')) !== '')) {
        return [
            'success' => false,
            'message' => whatsapp_outbound_error_message((string) ($networkProbe['curl_error'] ?? '')),
        ];
    }

    // 1) Treat input as Phone Number ID
    $direct = $networkProbe['http_code'] === 200 && !empty($networkProbe['data']['id'])
        ? $networkProbe
        : whatsapp_graph_get(
            rawurlencode($phoneId) . '?fields=verified_name,display_phone_number,id',
            $token
        );

    if ($direct['http_code'] === 200 && !empty($direct['data']['id'])) {
        $display = trim($direct['data']['display_phone_number'] ?? '');
        $appCheck = whatsapp_check_token_app_match($token);
        if ($appCheck !== null) {
            return $appCheck;
        }
        $inspect = whatsapp_inspect_token($token);
        $message = 'WhatsApp connected successfully.' . whatsapp_token_expiry_warning($inspect);
        if ($display !== '') {
            $message .= ' Number: ' . $display;
        }
        return ['success' => true, 'message' => $message, 'phone_id' => (string) $direct['data']['id']];
    }

    // 2) Maybe user pasted WhatsApp Business Account ID — list phone numbers
    $waba = whatsapp_graph_get(
        rawurlencode($phoneId) . '/phone_numbers?fields=id,display_phone_number,verified_name',
        $token
    );

    if ($waba['http_code'] === 200 && !empty($waba['data']['data'][0]['id'])) {
        $phone = $waba['data']['data'][0];
        $resolvedId = (string) $phone['id'];
        $display = trim($phone['display_phone_number'] ?? '');

        $appCheck = whatsapp_check_token_app_match($token);
        if ($appCheck !== null) {
            return $appCheck;
        }
        $inspect = whatsapp_inspect_token($token);

        return [
            'success'  => true,
            'message'  => 'Connected. You entered a Business Account ID — use Phone number ID '
                . $resolvedId . ($display !== '' ? ' (' . $display . ')' : '') . ' going forward.'
                . whatsapp_token_expiry_warning($inspect),
            'phone_id' => $resolvedId,
        ];
    }

    // 3) Token scope hint
    $inspect = whatsapp_inspect_token($token);
    if (!empty($inspect['inspect_failed'])) {
        return [
            'success' => false,
            'message' => 'Could not inspect token with Meta. '
                . ($inspect['reason'] ?? 'Check config.php: META_APP_ID and META_APP_SECRET must be from the same Meta app (Settings → Basic).'),
        ];
    }
    if (($inspect['is_valid'] ?? null) === false) {
        return ['success' => false, 'message' => 'Access token is invalid or expired. Generate a new token in Meta and try again.'];
    }
    if (($inspect['is_valid'] ?? null) === true && ($inspect['has_whatsapp_scope'] ?? false) === false) {
        return [
            'success' => false,
            'message' => 'Token is valid but missing WhatsApp permissions. Regenerate the token with whatsapp_business_messaging and whatsapp_business_management, and assign your WhatsApp account to the system user.',
        ];
    }

    $err = $direct['data']['error']['message'] ?? $waba['data']['error']['message'] ?? 'Invalid Phone Number ID or access token.';
    return ['success' => false, 'message' => whatsapp_friendly_error($err, $phoneId)];
}

/**
 * Fetch webhook subscriptions registered on Meta for this app.
 *
 * @return array{success: bool, callback_url?: string, fields?: string[], raw?: array, error?: string}
 */
function meta_app_webhook_subscriptions(): array
{
    $appId = whatsapp_meta_app_id();
    $appSecret = whatsapp_meta_app_secret();
    if ($appId === '' || $appSecret === '') {
        return ['success' => false, 'error' => 'Meta App ID or App Secret missing'];
    }

    $appToken = $appId . '|' . $appSecret;
    $version = whatsapp_graph_api_version();
    $url = 'https://graph.facebook.com/' . $version . '/' . $appId . '/subscriptions?access_token=' . urlencode($appToken);

    $result = whatsapp_curl_request($url, [CURLOPT_TIMEOUT => 12]);
    if (!$result['ok']) {
        return ['success' => false, 'error' => whatsapp_outbound_error_message($result['curl_error'])];
    }

    $data = json_decode(is_string($result['body']) ? $result['body'] : '', true);
    if (!is_array($data)) {
        return ['success' => false, 'error' => 'Could not read Meta subscriptions'];
    }

    if (!empty($data['error'])) {
        return ['success' => false, 'error' => $data['error']['message'] ?? 'Meta API error'];
    }

    $items = $data['data'] ?? [];
    $callbackUrl = '';
    $fields = [];
    foreach ($items as $item) {
        if (!empty($item['callback_url'])) {
            $callbackUrl = $item['callback_url'];
        }
        if (!empty($item['fields']) && is_array($item['fields'])) {
            foreach ($item['fields'] as $field) {
                if (is_string($field)) {
                    $fields[] = $field;
                } elseif (is_array($field) && !empty($field['name'])) {
                    $fields[] = $field['name'];
                }
            }
        }
        // Some API versions nest object + fields differently
        if (!empty($item['object']) && $item['object'] === 'whatsapp_business_account') {
            $callbackUrl = $item['callback_url'] ?? $callbackUrl;
        }
    }

    return [
        'success'      => true,
        'callback_url' => $callbackUrl,
        'fields'       => array_values(array_unique($fields)),
        'raw'          => $items,
    ];
}

/**
 * POST to Meta Graph API.
 *
 * @return array{http_code: int, data: array<string, mixed>}
 */
function whatsapp_graph_post(string $path, string $token, array $body = []): array
{
    $version = whatsapp_graph_api_version();
    $url = 'https://graph.facebook.com/' . $version . '/' . ltrim($path, '/');

    $result = whatsapp_curl_request($url, [
        CURLOPT_POST       => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
        ],
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_TIMEOUT    => 15,
    ]);

    $data = json_decode(is_string($result['body']) ? $result['body'] : '', true);
    if (!is_array($data)) {
        $data = [];
    }

    return [
        'http_code'  => $result['http_code'],
        'data'       => $data,
        'curl_error' => $result['curl_error'],
        'curl_ok'    => $result['ok'],
    ];
}

/**
 * Check if WABA is subscribed to this Meta app (required for inbound webhooks).
 *
 * @return array{success: bool, subscribed?: bool, waba_id?: string, apps?: array, error?: string}
 */
function whatsapp_waba_subscription_status(string $wabaId, string $token): array
{
    $wabaId = trim($wabaId);
    if ($wabaId === '') {
        return ['success' => false, 'error' => 'WABA ID missing'];
    }

    $result = whatsapp_graph_get(rawurlencode($wabaId) . '/subscribed_apps', $token);
    if ($result['http_code'] >= 400) {
        return [
            'success' => false,
            'error'   => $result['data']['error']['message'] ?? 'Could not read WABA subscription',
        ];
    }

    $apps = $result['data']['data'] ?? [];
    $appId = defined('META_APP_ID') ? (string) META_APP_ID : '';
    $subscribed = false;
    foreach ($apps as $app) {
        if ((string) ($app['id'] ?? $app['whatsapp_business_api_data']['id'] ?? '') === $appId) {
            $subscribed = true;
            break;
        }
        if ((string) ($app['whatsapp_business_api_data']['linked_account_id'] ?? '') !== '') {
            $subscribed = true;
        }
    }

    // Meta sometimes returns nested structure — any data row means subscribed for token's app
    if (!$subscribed && $apps !== [] && $appId === '') {
        $subscribed = true;
    }
    if (!$subscribed && $apps !== []) {
        foreach ($apps as $app) {
            if (is_array($app) && !empty($app['id'])) {
                if ($appId === '' || (string) $app['id'] === $appId) {
                    $subscribed = true;
                    break;
                }
            }
        }
    }

    return [
        'success'    => true,
        'subscribed' => $subscribed,
        'waba_id'    => $wabaId,
        'apps'       => $apps,
    ];
}

/**
 * Subscribe WABA to this Meta app so inbound messages trigger webhooks.
 *
 * @return array{success: bool, message?: string, error?: string}
 */
function whatsapp_subscribe_waba_to_app(string $wabaId, string $token): array
{
    $status = whatsapp_waba_subscription_status($wabaId, $token);
    if (!empty($status['success']) && !empty($status['subscribed'])) {
        return ['success' => true, 'message' => 'WABA already subscribed to app.'];
    }

    $result = whatsapp_graph_post(rawurlencode($wabaId) . '/subscribed_apps', $token, []);
    if ($result['http_code'] >= 200 && $result['http_code'] < 300) {
        return ['success' => true, 'message' => 'WABA subscribed to app for inbound webhooks.'];
    }

    return [
        'success' => false,
        'error'   => $result['data']['error']['message'] ?? 'Could not subscribe WABA to app',
    ];
}

/**
 * Resolve WABA IDs from access token.
 *
 * @return string[]
 */
function whatsapp_waba_ids_from_token(string $token): array
{
    $inspect = whatsapp_inspect_token($token);
    return $inspect['waba_ids'] ?? [];
}

/**
 * Send an approved WhatsApp template message (for marketing / outside 24h window).
 *
 * @param array<int, array<string, mixed>> $bodyParameters Optional {{1}} {{2}} body params
 * @return array{success: bool, message?: string, http_code?: int}
 */
function send_whatsapp_template(
    string $phoneId,
    string $token,
    string $to,
    string $templateName,
    string $languageCode = 'en',
    array $bodyParameters = []
): array {
    $version = whatsapp_graph_api_version();
    $url = 'https://graph.facebook.com/' . $version . '/' . $phoneId . '/messages';

    $components = [];
    if ($bodyParameters !== []) {
        $params = [];
        foreach ($bodyParameters as $text) {
            $params[] = ['type' => 'text', 'text' => (string) $text];
        }
        $components[] = [
            'type'       => 'body',
            'parameters' => $params,
        ];
    }

    $template = [
        'name'     => $templateName,
        'language' => ['code' => $languageCode],
    ];
    if ($components !== []) {
        $template['components'] = $components;
    }

    $payload = json_encode([
        'messaging_product' => 'whatsapp',
        'to'                => preg_replace('/\D/', '', $to),
        'type'              => 'template',
        'template'          => $template,
    ]);

    $result = whatsapp_curl_request($url, [
        CURLOPT_POST       => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
        ],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT    => 15,
    ]);

    if (!$result['ok']) {
        $msg = whatsapp_outbound_error_message($result['curl_error']);
        error_log('WhatsApp template send curl error: ' . $result['curl_error']);
        return ['success' => false, 'message' => $msg, 'http_code' => 0, 'curl_error' => $result['curl_error']];
    }

    if ($result['http_code'] >= 400) {
        $errBody = is_string($result['body']) ? $result['body'] : '';
        error_log('WhatsApp template send failed (' . $result['http_code'] . '): ' . $errBody);
        $decoded = json_decode($errBody, true);
        $metaErr = $decoded['error']['message'] ?? 'Failed to send template message.';
        return ['success' => false, 'message' => $metaErr, 'http_code' => $result['http_code']];
    }

    return ['success' => true];
}

/**
 * POST JSON payload to WhatsApp Cloud API messages endpoint.
 *
 * @param array<string, mixed> $body
 * @return array{success: bool, message?: string, http_code?: int}
 */
function whatsapp_send_payload(string $phoneId, string $token, array $body): array
{
    $version = whatsapp_graph_api_version();
    $url = 'https://graph.facebook.com/' . $version . '/' . $phoneId . '/messages';
    $body['messaging_product'] = 'whatsapp';
    $body['to'] = preg_replace('/\D/', '', (string) ($body['to'] ?? ''));

    $payload = json_encode($body);
    $result = whatsapp_curl_request($url, [
        CURLOPT_POST       => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
        ],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT    => 20,
    ]);

    if (!$result['ok']) {
        $msg = whatsapp_outbound_error_message($result['curl_error']);
        error_log('WhatsApp payload send curl error: ' . $result['curl_error']);
        return ['success' => false, 'message' => $msg, 'http_code' => 0, 'curl_error' => $result['curl_error']];
    }

    if ($result['http_code'] >= 400) {
        $errBody = is_string($result['body']) ? $result['body'] : '';
        error_log('WhatsApp payload send failed (' . $result['http_code'] . '): ' . $errBody);
        $decoded = json_decode($errBody, true);
        $metaErr = $decoded['error']['message'] ?? 'Failed to send WhatsApp message.';
        return ['success' => false, 'message' => $metaErr, 'http_code' => $result['http_code']];
    }

    return ['success' => true];
}

/**
 * Send image message with optional caption.
 */
function send_whatsapp_image(string $phoneId, string $token, string $to, string $imageUrl, string $caption = ''): array
{
    $image = ['link' => trim($imageUrl)];
    if ($caption !== '') {
        $image['caption'] = mb_substr($caption, 0, 1024);
    }

    return whatsapp_send_payload($phoneId, $token, [
        'to'   => $to,
        'type' => 'image',
        'image' => $image,
    ]);
}

/**
 * Send interactive reply buttons (max 3). Does not require Meta Commerce catalog.
 *
 * @param array<int, array{id: string, title: string}> $buttons
 */
function send_whatsapp_reply_buttons(
    string $phoneId,
    string $token,
    string $to,
    string $bodyText,
    array $buttons
): array {
    $payloadButtons = [];
    foreach (array_slice($buttons, 0, 3) as $btn) {
        $id = trim((string) ($btn['id'] ?? ''));
        $title = trim((string) ($btn['title'] ?? ''));
        if ($id === '' || $title === '') {
            continue;
        }
        $payloadButtons[] = [
            'type'  => 'reply',
            'reply' => [
                'id'    => mb_substr($id, 0, 256),
                'title' => mb_substr($title, 0, 20),
            ],
        ];
    }

    if ($payloadButtons === []) {
        return ['success' => false, 'message' => 'No valid buttons'];
    }

    return whatsapp_send_payload($phoneId, $token, [
        'to'   => $to,
        'type' => 'interactive',
        'interactive' => [
            'type' => 'button',
            'body' => ['text' => mb_substr(trim($bodyText), 0, 1024)],
            'action' => ['buttons' => $payloadButtons],
        ],
    ]);
}

/**
 * Send interactive list picker (max 10 rows). Does not require Meta Commerce catalog.
 *
 * @param array<int, array{title: string, rows: array<int, array{id: string, title: string, description?: string}>}> $sections
 */
function send_whatsapp_interactive_list(
    string $phoneId,
    string $token,
    string $to,
    string $bodyText,
    string $buttonLabel,
    array $sections
): array {
    $normalized = [];
    foreach (array_slice($sections, 0, 10) as $section) {
        $rows = [];
        foreach (array_slice($section['rows'] ?? [], 0, 10) as $row) {
            $id = trim((string) ($row['id'] ?? ''));
            $title = trim((string) ($row['title'] ?? ''));
            if ($id === '' || $title === '') {
                continue;
            }
            $entry = [
                'id'    => mb_substr($id, 0, 200),
                'title' => mb_substr($title, 0, 24),
            ];
            $desc = trim((string) ($row['description'] ?? ''));
            if ($desc !== '') {
                $entry['description'] = mb_substr($desc, 0, 72);
            }
            $rows[] = $entry;
        }
        if ($rows === []) {
            continue;
        }
        $normalized[] = [
            'title' => mb_substr(trim((string) ($section['title'] ?? 'Products')), 0, 24),
            'rows'  => $rows,
        ];
    }

    if ($normalized === []) {
        return ['success' => false, 'message' => 'No valid list rows'];
    }

    return whatsapp_send_payload($phoneId, $token, [
        'to'   => $to,
        'type' => 'interactive',
        'interactive' => [
            'type'   => 'list',
            'body'   => ['text' => mb_substr(trim($bodyText), 0, 1024)],
            'action' => [
                'button'   => mb_substr(trim($buttonLabel) !== '' ? trim($buttonLabel) : 'View menu', 0, 20),
                'sections' => $normalized,
            ],
        ],
    ]);
}

/**
 * Send single Meta catalog product card (requires linked WhatsApp catalog).
 */
function send_whatsapp_catalog_product(
    string $phoneId,
    string $token,
    string $to,
    string $catalogId,
    string $retailerId,
    string $bodyText = ''
): array {
    $interactive = [
        'type'   => 'product',
        'action' => [
            'catalog_id'          => $catalogId,
            'product_retailer_id' => $retailerId,
        ],
    ];
    if ($bodyText !== '') {
        $interactive['body'] = ['text' => mb_substr($bodyText, 0, 1024)];
    }

    return whatsapp_send_payload($phoneId, $token, [
        'to'          => $to,
        'type'        => 'interactive',
        'interactive' => $interactive,
    ]);
}

/**
 * Send Meta catalog product list (up to 30 products across sections).
 *
 * @param array<int, array{title: string, product_items: array<int, array{product_retailer_id: string}>}> $sections
 */
function send_whatsapp_catalog_product_list(
    string $phoneId,
    string $token,
    string $to,
    string $catalogId,
    array $sections,
    string $headerText = 'Our products',
    string $bodyText = 'Tap to browse'
): array {
    return whatsapp_send_payload($phoneId, $token, [
        'to'   => $to,
        'type' => 'interactive',
        'interactive' => [
            'type'   => 'product_list',
            'header' => ['type' => 'text', 'text' => mb_substr($headerText, 0, 60)],
            'body'   => ['text' => mb_substr($bodyText, 0, 1024)],
            'action' => [
                'catalog_id' => $catalogId,
                'sections'   => $sections,
            ],
        ],
    ]);
}

/**
 * Native WhatsApp "View catalog" — customer scrolls the full Meta Commerce catalog.
 */
function send_whatsapp_catalog_message(
    string $phoneId,
    string $token,
    string $to,
    string $bodyText = 'Tap to browse the full catalog',
    string $thumbnailRetailerId = ''
): array {
    $action = ['name' => 'catalog_message'];
    $thumb = trim($thumbnailRetailerId);
    if ($thumb !== '') {
        $action['parameters'] = ['thumbnail_product_retailer_id' => $thumb];
    }

    return whatsapp_send_payload($phoneId, $token, [
        'to'   => $to,
        'type' => 'interactive',
        'interactive' => [
            'type'   => 'catalog_message',
            'body'   => ['text' => mb_substr(trim($bodyText) !== '' ? trim($bodyText) : 'Tap to browse the full catalog', 0, 1024)],
            'action' => $action,
        ],
    ]);
}

/**
 * @return array{phone_id: string, token: string}|null
 */
function whatsapp_bot_credentials(int $botId, int $userId): ?array
{
    $bot = db_fetch(
        'SELECT whatsapp_phone_id, whatsapp_token, whatsapp_verified FROM bots WHERE id = ? AND user_id = ?',
        'ii',
        [$botId, $userId]
    );
    if (!$bot || empty($bot['whatsapp_verified']) || empty($bot['whatsapp_phone_id'])) {
        return null;
    }
    $token = bot_whatsapp_token_plain((string) ($bot['whatsapp_token'] ?? ''));
    if ($token === false || $token === '') {
        return null;
    }
    return [
        'phone_id' => (string) $bot['whatsapp_phone_id'],
        'token'    => (string) $token,
    ];
}

/**
 * Active embedded-signup display number for a client (E.164 / Meta format).
 */
function whatsapp_client_display_phone(int $clientId): string
{
    if ($clientId <= 0) {
        return '';
    }
    $row = db_fetch(
        'SELECT phone_display_number FROM client_whatsapp_accounts
         WHERE client_id = ? AND connection_status = \'active\'
         ORDER BY connected_at DESC LIMIT 1',
        'i',
        [$clientId]
    );

    return trim((string) ($row['phone_display_number'] ?? ''));
}

function whatsapp_is_placeholder_business_phone(string $phone): bool
{
    $trimmed = trim($phone);
    if ($trimmed === '') {
        return true;
    }
    $digits = preg_replace('/\D+/', '', $trimmed) ?? '';

    return in_array($digits, ['923001234567', '3001234567', '1234567890'], true)
        || preg_match('/^\+?92\s*300\s*123\s*4567$/', $trimmed) === 1;
}

/**
 * Copy connected WhatsApp number into users.phone when profile phone is empty or placeholder.
 */
function whatsapp_sync_user_business_phone(int $clientId, ?string $displayNumber = null): bool
{
    if ($clientId <= 0) {
        return false;
    }
    $displayNumber = trim($displayNumber ?? whatsapp_client_display_phone($clientId));
    if ($displayNumber === '') {
        return false;
    }

    $user = db_fetch('SELECT phone FROM users WHERE id = ? LIMIT 1', 'i', [$clientId]);
    if (!$user) {
        return false;
    }

    $current = trim((string) ($user['phone'] ?? ''));
    if ($current !== '' && !whatsapp_is_placeholder_business_phone($current)) {
        return false;
    }

    db_execute('UPDATE users SET phone = ? WHERE id = ?', 'si', [$displayNumber, $clientId]);

    return true;
}

if (!function_exists('bot_whatsapp_token_plain')) {
    require_once __DIR__ . '/whatsapp-token.php';
}
