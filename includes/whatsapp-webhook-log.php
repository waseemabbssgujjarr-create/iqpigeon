<?php
/**
 * Append-only webhook debug log (helps trace inbound delivery).
 */

function whatsapp_webhook_log_event(string $event, array $context = []): void
{
    if (!isset($context['event_id']) && !empty($GLOBALS['wa_webhook_event_id'])) {
        $context = ['event_id' => (string) $GLOBALS['wa_webhook_event_id']] + $context;
    }
    $line = date('Y-m-d H:i:s') . ' | ' . $event;
    if ($context !== []) {
        $line .= ' | ' . json_encode($context, JSON_UNESCAPED_UNICODE);
    }
    $line .= "\n";

    error_log('[WhatsApp webhook] ' . $event . ($context !== [] ? ' ' . json_encode($context) : ''));

    $dir = dirname(__DIR__) . '/storage';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $file = $dir . '/whatsapp-webhook.log';
    @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
}

/**
 * @return string[]
 */
function whatsapp_webhook_recent_logs(int $limit = 30): array
{
    $file = dirname(__DIR__) . '/storage/whatsapp-webhook.log';
    if (!is_file($file)) {
        return [];
    }

    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    if (count($lines) <= $limit) {
        return $lines;
    }

    return array_slice($lines, -$limit);
}
