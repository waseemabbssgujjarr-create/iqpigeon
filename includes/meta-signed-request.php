<?php
/**
 * Parse Meta signed_request payloads (deauthorize, data deletion callbacks).
 */
declare(strict_types=1);

/** @return array<string, mixed>|null */
function meta_parse_signed_request(string $signedRequest, string $appSecret): ?array
{
    $signedRequest = trim($signedRequest);
    if ($signedRequest === '' || $appSecret === '' || !str_contains($signedRequest, '.')) {
        return null;
    }

    [$encodedSig, $payload] = explode('.', $signedRequest, 2);
    $sig = base64_decode(strtr($encodedSig, '-_', '+/'), true);
    if ($sig === false) {
        return null;
    }

    $expectedSig = hash_hmac('sha256', $payload, $appSecret, true);
    if (!hash_equals($expectedSig, $sig)) {
        return null;
    }

    $json = base64_decode(strtr($payload, '-_', '+/'), true);
    $data = json_decode($json ?: '', true);

    return is_array($data) ? $data : null;
}
