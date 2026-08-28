<?php
/**
 * Classify Core failures for infrastructure (must-send). Core does not send.
 */
declare(strict_types=1);

/**
 * @return array{retryable: bool, fallback_safe: bool, media_failed: bool}
 */
function agent_core_classify_error(Throwable $e, array $turnCtx = []): array
{
    $msg = mb_strtolower($e->getMessage());
    $media = str_contains($msg, 'media') || str_contains($msg, 'whisper') || str_contains($msg, 'vision');

    return [
        'retryable'     => str_contains($msg, 'timeout') || str_contains($msg, '429') || str_contains($msg, 'unavailable'),
        'fallback_safe' => true,
        'media_failed'  => $media,
    ];
}
