<?php
/**
 * Disabled — a background typer without a message is the type/leave loop.
 */

require_once __DIR__ . '/../config.php';

function whatsapp_typing_session_dir(): string
{
    $dir = dirname(__DIR__) . '/storage/typing-sessions';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    return $dir;
}

function whatsapp_typing_stop_path(string $messageId): string
{
    return whatsapp_typing_session_dir() . '/stop-' . md5(trim($messageId)) . '.flag';
}

function whatsapp_typing_active_path(string $messageId): string
{
    return whatsapp_typing_session_dir() . '/active-' . md5(trim($messageId)) . '.txt';
}

/**
 * Typing keepalive is disabled.
 *
 * A background pulse (type → sleep → type) with no message is exactly the
 * "typing, leaving, typing, leaving" bug. The turn engine types once, then sends.
 */
function whatsapp_typing_keepalive_start(string $phoneId, string $token, string $messageId): void
{
    $phoneId = trim($phoneId);
    $token = trim($token);
    unset($phoneId, $token);
    whatsapp_typing_keepalive_stop($messageId);
}

function whatsapp_typing_keepalive_stop(string $messageId): void
{
    $messageId = trim($messageId);
    if ($messageId === '') {
        return;
    }

    @file_put_contents(whatsapp_typing_stop_path($messageId), (string) time(), LOCK_EX);
    @file_put_contents(whatsapp_typing_active_path($messageId), 'stopped', LOCK_EX);
}

function whatsapp_typing_keepalive_run(string $sessionId): void
{
    $sessionId = preg_replace('/[^a-f0-9]/', '', strtolower($sessionId)) ?? '';
    if ($sessionId === '') {
        return;
    }

    $sessionFile = whatsapp_typing_session_dir() . '/' . $sessionId . '.json';
    if (is_file($sessionFile)) {
        $data = json_decode((string) @file_get_contents($sessionFile), true);
        if (is_array($data) && !empty($data['message_id'])) {
            whatsapp_typing_keepalive_stop((string) $data['message_id']);
        }
        @unlink($sessionFile);
    }
}
