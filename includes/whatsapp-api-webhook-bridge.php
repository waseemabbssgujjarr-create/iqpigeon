<?php

declare(strict_types=1);

/**
 * Forward verified Meta WhatsApp webhooks to IQPigeon WhatsApp API (additive bridge).
 *
 * Uses the same Meta app secret signature as the primary webhook; forwards raw body
 * and X-Hub-Signature-256 unchanged. Never logs secrets or full payloads.
 */

function whatsapp_api_bridge_enabled(): bool
{
    if (array_key_exists('whatsapp_api_bridge_enabled_override', $GLOBALS)) {
        return (bool) $GLOBALS['whatsapp_api_bridge_enabled_override'];
    }

    if (defined('WHATSAPP_API_WEBHOOK_BRIDGE_ENABLED')) {
        return (bool) constant('WHATSAPP_API_WEBHOOK_BRIDGE_ENABLED');
    }

    return false;
}

function whatsapp_api_bridge_target_url(): string
{
    if (defined('WHATSAPP_API_WEBHOOK_BRIDGE_URL')) {
        $url = trim((string) constant('WHATSAPP_API_WEBHOOK_BRIDGE_URL'));
        if ($url !== '') {
            return rtrim($url, '/');
        }
    }

    return 'https://whatsappapi.iqpigeon.com/webhooks/meta';
}

/**
 * @return array<string, true>
 */
function whatsapp_api_bridge_phone_number_id_map(): array
{
    $raw = defined('WHATSAPP_API_BRIDGE_PHONE_NUMBER_IDS')
        ? (string) constant('WHATSAPP_API_BRIDGE_PHONE_NUMBER_IDS')
        : '';

    $map = [];
    foreach (preg_split('/\s*,\s*/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $id) {
        $id = trim($id);
        if ($id !== '') {
            $map[$id] = true;
        }
    }

    return $map;
}

/**
 * @return array<string, true>
 */
function whatsapp_api_bridge_waba_id_map(): array
{
    $raw = defined('WHATSAPP_API_BRIDGE_WABA_IDS')
        ? (string) constant('WHATSAPP_API_BRIDGE_WABA_IDS')
        : '';

    $map = [];
    foreach (preg_split('/\s*,\s*/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $id) {
        $id = trim($id);
        if ($id !== '') {
            $map[$id] = true;
        }
    }

    return $map;
}

function whatsapp_api_bridge_change_matches(array $entry, array $change): bool
{
    $phones = whatsapp_api_bridge_phone_number_id_map();
    $wabas = whatsapp_api_bridge_waba_id_map();

    if ($phones === [] && $wabas === []) {
        return false;
    }

    $entryWaba = trim((string) ($entry['id'] ?? ''));
    $value = is_array($change['value'] ?? null) ? $change['value'] : [];
    $phoneId = trim((string) ($value['metadata']['phone_number_id'] ?? ''));

    if ($phoneId !== '' && isset($phones[$phoneId])) {
        return true;
    }

    if ($entryWaba !== '' && isset($wabas[$entryWaba])) {
        return true;
    }

    return false;
}

/**
 * @return 'inbound_message'|'status_event'|null
 */
function whatsapp_api_bridge_event_type_for_change(array $change): ?string
{
    $value = is_array($change['value'] ?? null) ? $change['value'] : [];
    $messages = $value['messages'] ?? [];
    $statuses = $value['statuses'] ?? [];

    if (is_array($messages) && $messages !== []) {
        return 'inbound_message';
    }

    if (is_array($statuses) && $statuses !== []) {
        return 'status_event';
    }

    return null;
}

/**
 * Decide whether to forward this verified webhook to the WhatsApp API SaaS.
 *
 * @param array<string, mixed> $data Decoded Meta webhook JSON
 * @return array{forward: bool, phone_number_id: string, event_type: string}|null
 */
function whatsapp_api_bridge_plan_forward(array $data): ?array
{
    if (!whatsapp_api_bridge_enabled()) {
        return null;
    }

    if (!is_array($data['entry'] ?? null)) {
        return null;
    }

    foreach ($data['entry'] as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        foreach ($entry['changes'] ?? [] as $change) {
            if (!is_array($change)) {
                continue;
            }

            $field = (string) ($change['field'] ?? '');
            if (in_array($field, ['history', 'smb_app_state_sync', 'smb_message_echoes', 'account_update'], true)) {
                continue;
            }

            if (!whatsapp_api_bridge_change_matches($entry, $change)) {
                continue;
            }

            $eventType = whatsapp_api_bridge_event_type_for_change($change);
            if ($eventType === null) {
                continue;
            }

            $value = is_array($change['value'] ?? null) ? $change['value'] : [];
            $phoneId = trim((string) ($value['metadata']['phone_number_id'] ?? ''));

            return [
                'forward' => true,
                'phone_number_id' => $phoneId,
                'event_type' => $eventType,
            ];
        }
    }

    return null;
}

/**
 * @return array{http_status: int, curl_error: string}
 */
function whatsapp_api_bridge_http_post(string $url, string $rawBody, string $signatureHeader, int $timeoutMs): array
{
    if (isset($GLOBALS['whatsapp_api_bridge_http_post_handler'])
        && is_callable($GLOBALS['whatsapp_api_bridge_http_post_handler'])) {
        /** @var array{http_status: int, curl_error: string} */
        return ($GLOBALS['whatsapp_api_bridge_http_post_handler'])($url, $rawBody, $signatureHeader, $timeoutMs);
    }

    $ch = curl_init($url);
    if ($ch === false) {
        return ['http_status' => 0, 'curl_error' => 'curl_init failed'];
    }

    $headers = [
        'Content-Type: application/json',
        'X-Hub-Signature-256: ' . $signatureHeader,
        'User-Agent: IQPigeon-WhatsApp-Webhook-Bridge/1.0',
    ];

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $rawBody,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT_MS => max(500, $timeoutMs),
        CURLOPT_CONNECTTIMEOUT_MS => max(300, (int) min(2000, $timeoutMs)),
    ]);

    curl_exec($ch);
    $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = trim((string) curl_error($ch));
    curl_close($ch);

    return ['http_status' => $httpStatus, 'curl_error' => $curlError];
}

function whatsapp_api_bridge_forward_verified_payload(
    string $rawBody,
    string $signatureHeader,
    string $eventId,
    string $phoneNumberId,
    string $eventType,
): void {
    if ($rawBody === '' || $signatureHeader === '') {
        return;
    }

    $url = whatsapp_api_bridge_target_url();
    $timeoutMs = defined('WHATSAPP_API_WEBHOOK_BRIDGE_TIMEOUT_MS')
        ? max(500, (int) constant('WHATSAPP_API_WEBHOOK_BRIDGE_TIMEOUT_MS'))
        : 2500;

    $result = whatsapp_api_bridge_http_post($url, $rawBody, $signatureHeader, $timeoutMs);
    $httpStatus = (int) ($result['http_status'] ?? 0);
    $curlError = (string) ($result['curl_error'] ?? '');

    $safeContext = [
        'event_id' => $eventId,
        'phone_number_id' => $phoneNumberId !== '' ? $phoneNumberId : null,
        'event_type' => $eventType,
        'http_status' => $httpStatus > 0 ? $httpStatus : null,
        'stage' => 'whatsapp_api_bridge',
    ];

    if ($httpStatus >= 200 && $httpStatus < 300) {
        if (function_exists('whatsapp_webhook_log_event')) {
            whatsapp_webhook_log_event('WhatsApp API bridge forward ok', array_filter($safeContext));
        }

        return;
    }

    if ($curlError !== '') {
        $safeContext['curl_error'] = substr($curlError, 0, 120);
    }

    if (function_exists('whatsapp_webhook_log_event')) {
        whatsapp_webhook_log_event('WhatsApp API bridge forward failed', array_filter($safeContext));
    }
}
