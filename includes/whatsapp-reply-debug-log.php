<?php
/**
 * Lightweight reply debug logger — safe to include from webhook (no exec, no DB checks).
 */

function whatsapp_reply_debug_log_path(): string
{
    return dirname(__DIR__) . '/storage/logs/wa-reply-debug.jsonl';
}

function whatsapp_reply_debug_log(string $step, array $context = []): void
{
    $dir = dirname(whatsapp_reply_debug_log_path());
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $line = json_encode([
        'ts'      => date('c'),
        'step'    => $step,
        'context' => $context,
    ], JSON_UNESCAPED_UNICODE) . "\n";
    @file_put_contents(whatsapp_reply_debug_log_path(), $line, FILE_APPEND | LOCK_EX);
}

/**
 * @return list<array<string, mixed>>
 */
function whatsapp_reply_debug_read(int $limit = 40): array
{
    $path = whatsapp_reply_debug_log_path();
    if (!is_file($path)) {
        return [];
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $out = [];
    foreach (array_slice($lines, -$limit) as $line) {
        $row = json_decode($line, true);
        if (is_array($row)) {
            $out[] = $row;
        }
    }

    return $out;
}
