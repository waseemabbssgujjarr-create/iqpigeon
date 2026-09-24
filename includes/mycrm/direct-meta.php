<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

/**
 * DIRECT_META transport — optional friend CRM path using Meta Graph from server env.
 * Not used when MYCRM_TRANSPORT=iqpigeon_api.
 *
 * @return array{ok: bool, error?: string, data?: array<string, mixed>}
 */
function mycrm_direct_meta_send_text(string $to, string $body): array
{
    if (mycrm_is_iqpigeon_api_mode()) {
        return ['ok' => false, 'error' => 'Direct Meta send blocked in iqpigeon_api mode'];
    }

    $token = mycrm_env('MYCRM_META_ACCESS_TOKEN');
    $phoneId = mycrm_env('MYCRM_META_PHONE_NUMBER_ID');
    $version = mycrm_env('MYCRM_META_GRAPH_VERSION', 'v21.0');

    if ($token === '' || $phoneId === '') {
        return ['ok' => false, 'error' => 'MYCRM_META_ACCESS_TOKEN and MYCRM_META_PHONE_NUMBER_ID required for direct_meta'];
    }

    $url = 'https://graph.facebook.com/' . $version . '/' . $phoneId . '/messages';
    $payload = [
        'messaging_product' => 'whatsapp',
        'to' => preg_replace('/\D+/', '', $to),
        'type' => 'text',
        'text' => ['body' => $body],
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
        CURLOPT_TIMEOUT => 30,
    ]);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false) {
        return ['ok' => false, 'error' => 'Network error contacting Meta'];
    }

    $data = json_decode($raw, true);
    if ($code >= 400) {
        return ['ok' => false, 'error' => is_array($data) ? (string) ($data['error']['message'] ?? 'Meta error') : 'Meta error'];
    }

    return ['ok' => true, 'data' => is_array($data) ? $data : []];
}
