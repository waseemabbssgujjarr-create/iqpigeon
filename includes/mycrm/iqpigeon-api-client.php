<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/storage.php';

/**
 * @return array{ok: bool, status: int, body: array<string, mixed>|null, raw: string, error?: string}
 */
function mycrm_iqpigeon_request(string $method, string $path, ?array $json = null, ?string $idempotencyKey = null): array
{
    if (!mycrm_is_iqpigeon_api_mode()) {
        return ['ok' => false, 'status' => 0, 'body' => null, 'raw' => '', 'error' => 'Transport is not iqpigeon_api'];
    }

    $key = mycrm_iqpigeon_api_key();
    if ($key === '') {
        return ['ok' => false, 'status' => 0, 'body' => null, 'raw' => '', 'error' => 'IQPIGEON_API_KEY is not configured'];
    }

    $url = mycrm_iqpigeon_base_url() . '/api/v1' . $path;
    $headers = [
        'Authorization: Bearer ' . $key,
        'Accept: application/json',
        'Content-Type: application/json',
    ];
    if ($idempotencyKey !== null && $idempotencyKey !== '') {
        $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
    }

    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 45,
    ];
    if ($json !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($json, JSON_THROW_ON_ERROR);
    }
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        return ['ok' => false, 'status' => $status, 'body' => null, 'raw' => '', 'error' => $curlErr ?: 'Network error'];
    }

    $decoded = json_decode($raw, true);
    $body = is_array($decoded) ? $decoded : null;
    $ok = $status >= 200 && $status < 300 && is_array($body) && ($body['success'] ?? false) === true;

    return [
        'ok' => $ok,
        'status' => $status,
        'body' => $body,
        'raw' => $raw,
        'error' => $ok ? null : (is_array($body) ? (string) ($body['error']['message'] ?? 'API error') : 'Invalid JSON response'),
    ];
}

/**
 * @param  array{status?: int, body?: array<string, mixed>|null, error?: string|null}  $resp
 * @return array{http_status: int, code: string, message: string}
 */
function mycrm_iqpigeon_parse_api_error(array $resp): array
{
    $httpStatus = (int) ($resp['status'] ?? 0);
    $body = $resp['body'] ?? null;
    $message = trim((string) ($resp['error'] ?? 'API error'));
    $code = 'unknown';

    if (is_array($body) && isset($body['error']) && is_array($body['error'])) {
        $apiCode = $body['error']['code'] ?? null;
        if ($apiCode !== null && (string) $apiCode !== '') {
            $code = (string) $apiCode;
        }
        $apiMessage = trim((string) ($body['error']['message'] ?? ''));
        if ($apiMessage !== '') {
            $message = $apiMessage;
        }
    }

    return [
        'http_status' => $httpStatus,
        'code' => $code,
        'message' => $message !== '' ? $message : 'API error',
    ];
}

/**
 * @param  array<string, mixed>  $result  Send helper result when ok=false.
 */
function mycrm_iqpigeon_format_api_error_line(array $result): string
{
    $http = (int) ($result['http_status'] ?? $result['status'] ?? 0);
    $code = trim((string) ($result['error_code'] ?? 'unknown'));
    if ($code === '') {
        $code = 'unknown';
    }
    $message = trim((string) ($result['error'] ?? 'Unknown error'));

    $line = 'HTTP '.($http > 0 ? (string) $http : '?').' — code '.$code.' — '.$message;
    $requestId = trim((string) ($result['request_id'] ?? ''));
    if ($requestId !== '') {
        $line .= ' (request_id '.$requestId.')';
    }

    return $line;
}

/**
 * @return array{ok: bool, partner?: array<string, mixed>, error?: string, request_id?: string|null}
 */
function mycrm_iqpigeon_test_connection(): array
{
    $me = mycrm_iqpigeon_request('GET', '/me');
    if (!$me['ok'] || !is_array($me['body'])) {
        mycrm_set_state([
            'last_api_test_at' => gmdate('c'),
            'last_api_test_ok' => false,
        ]);

        return [
            'ok' => false,
            'error' => $me['error'] ?? 'API authentication failed',
            'error_code' => is_array($me['body']) ? (string) ($me['body']['error']['code'] ?? '') : '',
            'request_id' => is_array($me['body']) ? ($me['body']['request_id'] ?? null) : null,
        ];
    }

    $partner = $me['body']['data']['partner'] ?? null;
    $connId = mycrm_iqpigeon_connection_id();
    $connection = null;
    if ($connId !== '') {
        $list = mycrm_iqpigeon_request('GET', '/connections');
        if ($list['ok'] && is_array($list['body']['data']['connections'] ?? null)) {
            foreach ($list['body']['data']['connections'] as $c) {
                if (is_array($c) && ($c['id'] ?? '') === $connId) {
                    $connection = $c;
                    break;
                }
            }
        }
    }

    mycrm_set_state([
        'last_api_test_at' => gmdate('c'),
        'last_api_test_ok' => true,
        'partner_status' => is_array($partner) ? ($partner['status'] ?? null) : null,
        'connection_status' => is_array($connection) ? ($connection['status'] ?? null) : null,
        'display_phone' => is_array($connection) ? ($connection['display_phone_number'] ?? null) : null,
    ]);

    return [
        'ok' => true,
        'partner' => is_array($partner) ? $partner : [],
        'connection' => $connection,
        'request_id' => $me['body']['request_id'] ?? null,
    ];
}

/**
 * @return array{ok: bool, message?: array<string, mixed>, error?: string, error_code?: string, request_id?: string|null, status?: int}
 */
function mycrm_iqpigeon_send_text(string $to, string $body, string $idempotencyKey): array
{
    $connectionId = mycrm_iqpigeon_connection_id();
    if ($connectionId === '') {
        return ['ok' => false, 'error' => 'IQPIGEON_CONNECTION_ID is not configured'];
    }

    $resp = mycrm_iqpigeon_request('POST', '/messages', [
        'connection_id' => $connectionId,
        'to' => $to,
        'type' => 'text',
        'body' => $body,
    ], $idempotencyKey);

    $requestId = is_array($resp['body']) ? ($resp['body']['request_id'] ?? null) : null;

    if (!$resp['ok']) {
        $parsed = mycrm_iqpigeon_parse_api_error($resp);

        return [
            'ok' => false,
            'error' => $parsed['message'],
            'error_code' => $parsed['code'],
            'http_status' => $parsed['http_status'],
            'request_id' => $requestId,
            'status' => $resp['status'],
        ];
    }

    $message = is_array($resp['body']) ? ($resp['body']['data']['message'] ?? null) : null;

    return [
        'ok' => true,
        'message' => is_array($message) ? $message : [],
        'request_id' => $requestId,
        'status' => $resp['status'],
    ];
}

function mycrm_normalize_to_for_api(string $to): string
{
    return preg_replace('/\D+/', '', trim($to)) ?? '';
}

/**
 * @param  array<string, mixed>  $template  Meta Cloud API template object (name, language, optional components).
 * @return array{ok: bool, message?: array<string, mixed>, error?: string, error_code?: string, request_id?: string|null, status?: int}
 */
function mycrm_iqpigeon_send_template(string $to, array $template, string $idempotencyKey): array
{
    $connectionId = mycrm_iqpigeon_connection_id();
    if ($connectionId === '') {
        return ['ok' => false, 'error' => 'IQPIGEON_CONNECTION_ID is not configured'];
    }

    $normalizedTo = mycrm_normalize_to_for_api($to);
    if ($normalizedTo === '') {
        return ['ok' => false, 'error' => 'Recipient phone number is empty or invalid'];
    }

    $name = trim((string) ($template['name'] ?? ''));
    if ($name === '') {
        return ['ok' => false, 'error' => 'Template name is required'];
    }

    $language = $template['language'] ?? null;
    if (! is_array($language)) {
        return ['ok' => false, 'error' => 'Template language is required'];
    }

    $languageCode = trim((string) ($language['code'] ?? ''));
    if ($languageCode === '') {
        return ['ok' => false, 'error' => 'Template language code is required'];
    }

    $payloadTemplate = [
        'name' => $name,
        'language' => ['code' => $languageCode],
    ];

    if (array_key_exists('components', $template) && is_array($template['components'])) {
        $payloadTemplate['components'] = $template['components'];
    }

    $resp = mycrm_iqpigeon_request('POST', '/messages', [
        'connection_id' => $connectionId,
        'to' => $normalizedTo,
        'type' => 'template',
        'template' => $payloadTemplate,
    ], $idempotencyKey);

    $requestId = is_array($resp['body']) ? ($resp['body']['request_id'] ?? null) : null;

    if (!$resp['ok']) {
        $parsed = mycrm_iqpigeon_parse_api_error($resp);

        return [
            'ok' => false,
            'error' => $parsed['message'],
            'error_code' => $parsed['code'],
            'http_status' => $parsed['http_status'],
            'request_id' => $requestId,
            'status' => $resp['status'],
        ];
    }

    $message = is_array($resp['body']) ? ($resp['body']['data']['message'] ?? null) : null;

    return [
        'ok' => true,
        'message' => is_array($message) ? $message : [],
        'request_id' => $requestId,
        'status' => $resp['status'],
    ];
}

/**
 * @return array{ok: bool, message?: array<string, mixed>, error?: string, request_id?: string|null}
 */
function mycrm_iqpigeon_get_message(string $uuid): array
{
    $uuid = trim($uuid);
    if ($uuid === '') {
        return ['ok' => false, 'error' => 'Message id is empty'];
    }

    $resp = mycrm_iqpigeon_request('GET', '/messages/' . rawurlencode($uuid));
    $requestId = is_array($resp['body']) ? ($resp['body']['request_id'] ?? null) : null;

    if (!$resp['ok']) {
        return [
            'ok' => false,
            'error' => $resp['error'] ?? 'Could not load message',
            'request_id' => $requestId,
        ];
    }

    $message = is_array($resp['body']) ? ($resp['body']['data']['message'] ?? null) : null;

    return [
        'ok' => true,
        'message' => is_array($message) ? $message : [],
        'request_id' => $requestId,
    ];
}

/**
 * Poll until the worker marks the message sent/failed (requires messages.read on the API key).
 *
 * @return array{ok: bool, message?: array<string, mixed>, still_queued?: bool, error?: string}
 */
function mycrm_iqpigeon_wait_for_send_status(string $uuid, int $maxAttempts = 8, int $sleepMs = 400): array
{
    $lastMessage = [];

    for ($i = 0; $i < $maxAttempts; $i++) {
        if ($i > 0) {
            usleep($sleepMs * 1000);
        }

        $got = mycrm_iqpigeon_get_message($uuid);
        if (!$got['ok']) {
            return $got;
        }

        $lastMessage = is_array($got['message'] ?? null) ? $got['message'] : [];
        $status = (string) ($lastMessage['status'] ?? '');

        if (in_array($status, ['sent', 'failed', 'delivered', 'read'], true)) {
            return ['ok' => true, 'message' => $lastMessage];
        }
    }

    return ['ok' => true, 'message' => $lastMessage, 'still_queued' => true];
}

/**
 * @param  array<string, mixed>  $result  Return value from mycrm_send_* helpers.
 */
function mycrm_iqpigeon_format_send_flash(array $result, string $verb): string
{
    if (!($result['ok'] ?? false)) {
        return $verb.' send failed: '.mycrm_iqpigeon_format_api_error_line($result);
    }

    $mid = (string) ($result['message']['id'] ?? 'n/a');
    $req = (string) ($result['request_id'] ?? 'n/a');
    $status = (string) ($result['message']['status'] ?? 'unknown');

    if ($status === 'failed') {
        $detail = trim((string) ($result['message']['failure_message'] ?? ''));
        if ($detail === '') {
            $detail = (string) ($result['message']['failure_code'] ?? 'Send failed');
        }

        return $verb . ' failed (message ' . $mid . '): ' . $detail . ' (request_id ' . $req . ')';
    }

    if ($status === 'queued') {
        $note = (string) ($result['delivery_note'] ?? 'Check queue workers on the WhatsApp API server (supervisor / redis).');

        return $verb . ' accepted (message ' . $mid . ') but still queued — not delivered to Meta yet. ' . $note;
    }

    $wa = trim((string) ($result['message']['wa_message_id'] ?? ''));
    $waPart = $wa !== '' ? ', wamid ' . $wa : '';

    return $verb . ' ' . $status . ' via IQPigeon API (message ' . $mid . ', status ' . $status . $waPart . ', request_id ' . $req . ')';
}

/**
 * @param  array<string, mixed>  $result
 * @return array<string, mixed>
 */
function mycrm_iqpigeon_enrich_send_result(array $result): array
{
    if (!($result['ok'] ?? false)) {
        return $result;
    }

    $messageId = trim((string) ($result['message']['id'] ?? ''));
    if ($messageId === '') {
        return $result;
    }

    $wait = mycrm_iqpigeon_wait_for_send_status($messageId);
    if (!($wait['ok'] ?? false)) {
        $result['delivery_note'] = 'Could not refresh status (API key needs messages.read): '
            . ($wait['error'] ?? 'unknown');

        return $result;
    }

    if (is_array($wait['message'] ?? null) && $wait['message'] !== []) {
        $result['message'] = array_merge($result['message'], $wait['message']);
    }

    if ($wait['still_queued'] ?? false) {
        $result['delivery_note'] = 'Still queued after ~3s — queue worker may be stopped on whatsappapi.iqpigeon.com.';
    }

    return $result;
}
