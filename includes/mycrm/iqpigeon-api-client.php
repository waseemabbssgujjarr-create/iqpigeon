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
        $code = is_array($resp['body']) ? (string) ($resp['body']['error']['code'] ?? 'api_error') : 'api_error';

        return [
            'ok' => false,
            'error' => $resp['error'] ?? 'Send failed',
            'error_code' => $code,
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
