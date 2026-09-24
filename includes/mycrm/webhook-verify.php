<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

function mycrm_iqpigeon_verify_webhook(string $rawBody, string $signature, string $timestamp, int $maxSkewSeconds = 300): bool
{
    $secret = mycrm_iqpigeon_webhook_secret();
    if ($secret === '' || $signature === '' || $timestamp === '') {
        return false;
    }

    if (!ctype_digit($timestamp)) {
        return false;
    }

    $ts = (int) $timestamp;
    if (abs(time() - $ts) > $maxSkewSeconds) {
        return false;
    }

    $expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
    $provided = $signature;
    if (str_contains($provided, '=')) {
        $parts = explode('=', $provided, 2);
        $provided = $parts[1] ?? $provided;
    }

    return hash_equals($expected, $provided);
}
