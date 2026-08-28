<?php
/**
 * Safe JSON helpers for /api/* endpoints — never let HTML leak to the client.
 */
declare(strict_types=1);

/**
 * Attach bot context when available; never throw to the caller.
 *
 * @param array<string, mixed> $payload
 * @return array<string, mixed>
 */
function api_json_with_context(int $botId, int $userId, array $payload): array
{
    if ($botId <= 0 || $userId <= 0) {
        return $payload;
    }

    try {
        require_once __DIR__ . '/bot-context.php';

        return bot_context_api_envelope($botId, $userId, $payload);
    } catch (Throwable $e) {
        error_log('api_json_with_context: ' . $e->getMessage());

        return $payload;
    }
}
